<?php

declare(strict_types=1);

namespace PhotosSh\Internal;

/**
 * Builds a `multipart/form-data` body as a string.
 *
 * @internal
 */
final class Multipart
{
    private string $boundary;

    private string $body = '';

    public function __construct(?string $boundary = null)
    {
        $this->boundary = $boundary ?? 'photos-sh-' . bin2hex(random_bytes(16));
    }

    /**
     * Adds a text field.
     */
    public function field(string $name, string $value): void
    {
        $this->body .= '--' . $this->boundary . "\r\n"
            . 'Content-Disposition: form-data; name="' . self::quote($name) . "\"\r\n\r\n"
            . $value . "\r\n";
    }

    /**
     * Adds a file part.
     */
    public function file(string $name, string $filename, string $contents): void
    {
        $this->body .= '--' . $this->boundary . "\r\n"
            . 'Content-Disposition: form-data; name="' . self::quote($name)
            . '"; filename="' . self::quote($filename) . "\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n"
            . $contents . "\r\n";
    }

    public function contentType(): string
    {
        return 'multipart/form-data; boundary=' . $this->boundary;
    }

    public function body(): string
    {
        return $this->body . '--' . $this->boundary . "--\r\n";
    }

    private static function quote(string $value): string
    {
        return str_replace(['"', "\r", "\n"], ['%22', '%0D', '%0A'], $value);
    }
}
