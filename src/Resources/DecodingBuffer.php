<?php
declare(strict_types=1);

namespace Onion\Framework\Http\Resources;
use Onion\Framework\Http\Resources\Processors\ChunkProcessor;
use Onion\Framework\Loop\Resources\Buffer;

class DecodingBuffer extends Buffer
{
    private bool $finished = false;
    private array $encoders = [];
    public function __construct(array $encodings = [])
    {
        parent::__construct();
        foreach ($encodings as $encoding) {
            $ctx = match ($encoding) {
                'gzip' => inflate_init(ZLIB_ENCODING_GZIP),
                'deflate' => inflate_init(ZLIB_ENCODING_DEFLATE),
                'chunked' => new ChunkProcessor(),
                default => throw new \InvalidArgumentException("Unknown encoding '{$encoding}'"),
            };

            $this->encoders[] =  match ($encoding) {
                'chunked' => [
                    fn (string $data) => $ctx->parse($data),
                    fn (string $data) => $data,
                ],
                default => [
                    fn (string $data) => inflate_add($ctx, $data, ZLIB_SYNC_FLUSH),
                    fn (string $data) => inflate_add($ctx, $data, ZLIB_FINISH),
                ],
            };
        }
    }

    public function write(string $data): int|false
    {
        if ($this->finished) {
            return false;
        }

        foreach ($this->encoders as [$encoder]) {
            $data = $encoder($data);
        }

        return parent::write($data);
    }

    public function __toString()
    {
        return parent::__toString() . $this->finish();
    }

    public function finish()
    {
        if ($this->finished) {
            return '';
        }

        $ch = '';
        foreach ($this->encoders as [,$finisher]) {
            $ch .= $finisher($ch);
        }

        parent::write($ch);

        $this->finished = true;
        return $ch;
    }
}
