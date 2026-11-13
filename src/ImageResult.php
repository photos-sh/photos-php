<?php

declare(strict_types=1);

namespace PhotosSh;

use PhotosSh\Exception\PhotosException;
use PhotosSh\Transport\Response;

/**
 * An image the API returned. Methods whose response says more about the
 * image return a subclass with those values.
 */
class ImageResult
{
    /** The encoded image. */
    public readonly string $bytes;

    /** For example `image/png`. */
    public readonly string $contentType;

    /** Width in pixels (`X-Image-Width`). */
    public readonly ?int $width;

    /** Height in pixels (`X-Image-Height`). */
    public readonly ?int $height;

    /** Quality used for a lossy format (`X-Image-Quality`); null otherwise. */
    public readonly ?int $quality;

    /** The raw `Server-Timing` header: per-step durations. */
    public readonly ?string $serverTiming;

    /** @var array<string, string> all response headers, names in lower case */
    public readonly array $headers;

    public function __construct(Response $response)
    {
        $this->bytes = $response->body;
        $this->contentType = $response->header('Content-Type') ?? 'application/octet-stream';
        $this->headers = $response->headers;
        $this->width = $this->intHeader('X-Image-Width');
        $this->height = $this->intHeader('X-Image-Height');
        $this->quality = $this->intHeader('X-Image-Quality');
        $this->serverTiming = $response->header('Server-Timing');
    }

    /**
     * Writes the image to a file.
     *
     * @throws PhotosException when the file cannot be written
     */
    public function save(string $path): void
    {
        if (@file_put_contents($path, $this->bytes) === false) {
            throw new PhotosException(sprintf('Cannot write the image to "%s".', $path));
        }
    }

    /**
     * A file extension for the image's format: `png`, `jpg`, `webp`, `avif`,
     * `svg` (`bin` for anything else).
     */
    public function extension(): string
    {
        return match (strtolower(trim(explode(';', $this->contentType)[0]))) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            'image/svg+xml' => 'svg',
            default => 'bin',
        };
    }

    /**
     * A header's value by case-insensitive name, or null when absent.
     */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    protected function intHeader(string $name): ?int
    {
        $value = $this->header($name);

        return $value !== null && is_numeric(trim($value)) ? (int) trim($value) : null;
    }

    protected function floatHeader(string $name): ?float
    {
        $value = $this->header($name);

        return $value !== null && is_numeric(trim($value)) ? (float) trim($value) : null;
    }

    /**
     * A `true`/`false` header; null when it is absent or says neither.
     */
    protected function boolHeader(string $name): ?bool
    {
        return match (strtolower(trim($this->header($name) ?? ''))) {
            'true' => true,
            'false' => false,
            default => null,
        };
    }

    /**
     * A comma-separated header as a list; empty when the header is absent
     * or empty.
     *
     * @return list<string>
     */
    protected function listHeader(string $name): array
    {
        $items = array_map(trim(...), explode(',', $this->header($name) ?? ''));

        return array_values(array_filter($items, static fn(string $item): bool => $item !== ''));
    }
}
