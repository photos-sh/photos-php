<?php

declare(strict_types=1);

namespace PhotosSh;

use PhotosSh\Exception\InvalidInputException;

/**
 * An image to send: a file on disk, bytes in memory, or an open stream.
 *
 * Methods that take an image accept a plain string too, which always means
 * a file path; wrap bytes in {@see Input::fromBytes()}.
 */
final class Input
{
    /**
     * @param resource|null $stream
     */
    private function __construct(
        private readonly ?string $path,
        private readonly ?string $bytes,
        private $stream,
        public readonly string $filename,
    ) {}

    /**
     * A file on disk. It is read when the request is made.
     *
     * @param string|null $filename name to send; defaults to the file's own
     */
    public static function fromPath(string $path, ?string $filename = null): self
    {
        if ($path === '') {
            throw new InvalidInputException('The image path is empty.');
        }
        $name = $filename ?? basename($path);

        return new self($path, null, null, $name !== '' ? $name : 'image');
    }

    /**
     * The encoded image (the contents of a JPEG, PNG, ... file) as a string.
     */
    public static function fromBytes(string $bytes, string $filename = 'image'): self
    {
        return new self(null, $bytes, null, $filename);
    }

    /**
     * An open, readable stream. It is read from its current position to the
     * end when the request is made, and not closed. A stream can be sent
     * once (a retry reuses what was read).
     *
     * @param resource $stream
     */
    public static function fromStream($stream, string $filename = 'image'): self
    {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new InvalidInputException('Input::fromStream() needs an open stream resource.');
        }

        return new self(null, null, $stream, $filename);
    }

    /**
     * Wraps a path in an Input; passes an Input through.
     *
     * @internal
     */
    public static function from(string|self $image): self
    {
        return $image instanceof self ? $image : self::fromPath($image);
    }

    /**
     * The image's bytes.
     *
     * @throws InvalidInputException when the file or stream cannot be read
     */
    public function contents(): string
    {
        if ($this->bytes !== null) {
            return $this->bytes;
        }
        if ($this->path !== null) {
            if (!is_file($this->path) || !is_readable($this->path)) {
                throw new InvalidInputException(sprintf('Cannot read the image file "%s".', $this->path));
            }
            $bytes = @file_get_contents($this->path);
            if ($bytes === false) {
                throw new InvalidInputException(sprintf('Cannot read the image file "%s".', $this->path));
            }

            return $bytes;
        }
        if (!is_resource($this->stream)) {
            throw new InvalidInputException('The image stream is closed.');
        }
        $bytes = stream_get_contents($this->stream);
        if ($bytes === false) {
            throw new InvalidInputException('Cannot read the image stream.');
        }

        return $bytes;
    }
}
