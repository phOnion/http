<?php
declare(strict_types=1);

namespace Onion\Framework\Http\Resources;
use Onion\Framework\Http\Resources\Processors\ChunkProcessor;
use Onion\Framework\Loop\Resources\Buffer;

class EncodingBuffer extends Buffer
{
    private array $encoders = [];
    private bool $finished = false;

    private $contents = '';

    public function __construct(array $encodings = [])
    {
        parent::__construct();
        $encodings = array_filter($encodings, fn ($encoding) => match (trim($encoding)) {
            'gzip', 'deflate', 'chunked' => true,
            default => false,
        });

        foreach ($encodings as $encoding) {
            $ctx = match ($encoding) {
                'gzip' => deflate_init(ZLIB_ENCODING_GZIP),
                'deflate' => deflate_init(ZLIB_ENCODING_DEFLATE),
                'chunked' => new ChunkProcessor(),
                default => throw new \InvalidArgumentException("Unknown encoding '{$encoding}'"),
            };
            $enc = trim($encoding);
            $this->encoders[$enc] =  match ($enc) {
                'chunked' => [
                    fn (string $data) => $ctx->generate($data),
                    fn (string $data) => $ctx->generate($data),
                ],
                default => [
                    fn (string $data) => deflate_add($ctx, $data, ZLIB_NO_FLUSH),
                    fn (string $data) => deflate_add($ctx, $data, ZLIB_FINISH)
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

    public function getAppliedEncodings()
    {
        return array_keys($this->encoders);
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
        foreach ($this->encoders as [, $finisher]) {
            $ch = $finisher($ch);
        }

        $this->finished = true;
        return $ch;
    }
}
