<?php

declare(strict_types=1);

namespace PhotosSh\Transport;

/**
 * An HTTP response.
 */
final class Response
{
    /** @var array<string, string> header name in lower case => value */
    public readonly array $headers;

    /**
     * @param array<string, string> $headers header name (any case) => value
     */
    public function __construct(
        public readonly int $status,
        array $headers = [],
        public readonly string $body = '',
    ) {
        $normalised = [];
        foreach ($headers as $name => $value) {
            $normalised[strtolower($name)] = $value;
        }
        $this->headers = $normalised;
    }

    /**
     * A header's value by case-insensitive name, or null when absent.
     */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
