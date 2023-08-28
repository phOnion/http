<?php
declare(strict_types=1);

namespace Onion\Framework\Http\Resources\Processors;
use Onion\Framework\Http\Resources\Processors\Types\ProcessorState;

class ChunkProcessor
{
    private ProcessorState $state = ProcessorState::SIZE;
    private int $size = 0;
    private string $sizeString = '';

    public function parse(string $data): string
    {
        $content = '';
        $cursor = 0;
        $length = strlen($data);

        while ($cursor < $length) {
            switch ($this->state) {
                case ProcessorState::TERMINATED:
                    return $content;
                case ProcessorState::SIZE:
                    $char = $data[$cursor];
                    if ($char === "\n") {
                        $this->size = hexdec(trim($this->sizeString));
                        $this->state = $this->size === 0 ? ProcessorState::TERMINATED : ProcessorState::DATA;
                        $this->sizeString = '';
                        break;
                    }

                    if ($char !== "\r") {
                        $this->sizeString .= $char;
                    }

                    break;
                case ProcessorState::DATA:
                    $content .= $data[$cursor];
                    $this->size--;

                    if ($this->size === 0) {
                        $cursor += 2;
                        $this->state = ProcessorState::SIZE;
                        $this->size = 0;
                        break;
                    }
                    break;
            }

            $cursor++;
        }

        return $content;
    }

    public function generate(string $data): string
    {
        return sprintf("%x\r\n%s\r\n", strlen($data), $data);
    }
}
