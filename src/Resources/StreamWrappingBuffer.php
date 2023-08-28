<?php
declare(strict_types=1);

namespace Onion\Framework\Http\Resources;
use Onion\Framework\Loop\Descriptor;
use Onion\Framework\Loop\Interfaces\ResourceInterface;
use Onion\Framework\Loop\Resources\Buffer;
use Psr\Http\Message\StreamInterface;

use function Onion\Framework\Loop\{read, suspend};

class StreamWrappingBuffer implements ResourceInterface, StreamInterface
{
	private readonly ResourceInterface $buffer;

    public function __construct(
        ResourceInterface|StreamInterface $buffer
    ) {
		if ($buffer instanceof StreamInterface) {
			$buffer = new Descriptor($buffer->detach());
		}

		$this->buffer = $buffer;
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
        $d = $this->buffer->read($size);

		return $d;
	}

	/**
	 * Attempt to write data to the underlying resource
	 *
	 * @param string $data The data to be written
	 * @return bool|int The amount of bytes written or false on error
	 */
	public function write(string $data): false|int
    {
        return $this->buffer->write($data);
	}

	/**
	 * Close the underlying resource
	 * @return bool Whether the operation succeeded or not
	 */
	public function close(): bool
	{
		return $this->buffer->close();
	}

	/**
	 * Attempt to make operations on the underlying resource blocking
	 * @return bool Whether the operation succeeded or not
	 */
	public function block(): bool
    {
        return $this->buffer->block();
	}

	/**
	 * Attempt to make operations on the underlying resource non-blocking
	 * @return bool Whether the operation succeeded or not
	 */
	public function unblock(): bool
    {
        return $this->buffer->unblock();
	}

	/**
	 * Returns the underlying resource
	 * @return resource
	 */
	public function getResource()
    {
        return $this->buffer->getResource();
	}

	/**
	 * Retrieve the numeric identifier of the underlying resource
	 * @return int
	 */
	public function getResourceId(): int
    {
        return $this->buffer->getResourceId();
	}

	/**
	 * Check whether the resource is still alive or not
	 * @return bool
	 */
	public function eof(): bool
    {
        return $this->buffer->eof();
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

	/**
	 * Reads all data from the stream into a string, from the beginning to end.
	 *
	 * This method MUST attempt to seek to the beginning of the stream before
	 * reading data and read the stream until the end is reached.
	 *
	 * Warning: This could attempt to load a large amount of data into memory.
	 *
	 * This method MUST NOT raise an exception in order to conform with PHP's
	 * string casting operations.
	 * @return string
	 */
	public function __toString()
    {
        return method_exists($this->buffer, '__toString') ?
			(string) $this->buffer :
			read($this->buffer, function (ResourceInterface $buffer) {
				$contents = '';
				while (!$buffer->eof()) {
					$contents .= $buffer->read(8192);
					suspend();
				}

				return $contents;
			});
	}

	/**
	 * Get the size of the stream if known.
	 * @return int|null Returns the size in bytes if known, or null if unknown.
	 */
	public function getSize()
    {
        return $this->buffer instanceof Buffer ? $this->buffer->size() : null;
	}

	/**
	 * Returns the current position of the file read/write pointer
	 * @return int Position of the file pointer
	 */
	public function tell()
    {
        return $this->buffer instanceof Buffer ? $this->buffer->tell() : 0;
	}

	/**
	 * Returns whether or not the stream is seekable.
	 * @return bool
	 */
	public function isSeekable()
    {
        return $this->buffer instanceof Buffer;
	}

	/**
	 * Seek to a position in the stream.
	 *
	 * @param int $offset Stream offset
	 * @param int $whence Specifies how the cursor position will be calculated
	 *                    based on the seek offset. Valid values are identical to the built-in
	 *                    PHP $whence values for `fseek()`.  SEEK_SET: Set position equal to
	 *                    offset bytes SEEK_CUR: Set position to current location plus offset
	 *                    SEEK_END: Set position to end-of-stream plus offset.
	 * @return mixed
	 */
	public function seek(int $offset, int $whence = SEEK_SET)
    {
        return $this->buffer instanceof Buffer ? $this->buffer->seek($offset, $whence) ?? 0 : -1;
	}

	/**
	 * Seek to the beginning of the stream.
	 *
	 * If the stream is not seekable, this method will raise an exception;
	 * otherwise, it will perform a seek(0).
	 * @return mixed
	 */
	public function rewind()
    {
        return $this->buffer instanceof Buffer ? $this->buffer->rewind() ?? 0 : 0;
	}

	/**
	 * Returns whether or not the stream is writable.
	 * @return bool
	 */
	public function isWritable()
    {
        return true;
	}

	/**
	 * Returns whether or not the stream is readable.
	 * @return bool
	 */
	public function isReadable()
    {
        return true;
	}

	/**
	 * Returns the remaining contents in a string
	 * @return string
	 */
	public function getContents()
    {
        return (string) $this;
	}

	/**
	 * Get stream metadata as an associative array or retrieve a specific key.
	 *
	 * The keys returned are identical to the keys returned from PHP's
	 * stream_get_meta_data() function.
	 *
	 * @param string|null $key Specific metadata to retrieve.
	 * @return array|mixed|null Returns an associative array if no key is
	 *                          provided. Returns a specific key value if a key is provided and the
	 *                          value is found, or null if the key is not found.
	 */
	public function getMetadata(?string $key = null) {
        return $this->buffer->getResource() !== null ? stream_get_meta_data($this->buffer->getResource()) : (is_array($key) ? null : []);
	}
}
