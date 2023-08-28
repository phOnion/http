<?php
declare(strict_types=1);

namespace Onion\Framework\Http\Resources;

use Onion\Framework\Http\Resources\Processors\ChunkProcessor;
use Onion\Framework\Loop\Interfaces\ResourceInterface;
use Onion\Framework\Loop\Resources\Buffer;

class ChunkingBuffer implements ResourceInterface
{
    private readonly ChunkProcessor $encoder;
	private bool $closed = false;

    public function __construct(
        private ?ResourceInterface $buffer = null
    ) {
        $this->encoder = new ChunkProcessor();
		$this->buffer ??= new Buffer();
    }

    public function write(string $data): int|false
    {
        return $this->buffer->write($this->encoder->generate($data));
    }

	/**
	 * Attempt to read data from the underlying resource
	 *
	 * @param int $size Maximum amount of bytes to read
	 * @return bool|string A string containing the data read or false
	 *                     if reading failed
	 */
	public function read(int $size): false|string
    {
        return $this->buffer->read($size);
	}

	/**
	 * Close the underlying resource
	 * @return bool Whether the operation succeeded or not
	 */
	public function close(): bool
    {
        $this->buffer->write($this->encoder->generate(''));
        return $this->buffer->close();
	}

	/**
	 * Attempt to make operations on the underlying resource blocking
	 * @return bool Whether the operation succeeded or not
	 */
	public function block(): bool
    {
        return true;
	}

	/**
	 * Attempt to make operations on the underlying resource non-blocking
	 * @return bool Whether the operation succeeded or not
	 */
	public function unblock(): bool
    {
        return true;
	}

	public function getResource(): mixed
    {
        return null;
	}

	/**
	 * Retrieve the numeric identifier of the underlying resource
	 * @return int
	 */
	public function getResourceId(): int
    {
        return -1;
	}

	/**
	 * Check whether the resource is still alive or not
	 * @return bool
	 */
	public function eof(): bool
    {
        return $this->closed;
	}

	/**
	 * Detaches the underlying resource from the current object and
	 * returns it, making the current object obsolete
	 * @return resource
	 */
	public function detach(): mixed
    {
        return $this->buffer->detach();
	}
}
