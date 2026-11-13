<?php

declare(strict_types=1);

namespace PhotosSh\Transport;

/**
 * An HTTP request, ready to send: the body is already encoded and the
 * headers are complete (including `Content-Type`).
 */
final class Request
{
    /**
     * @param string                $method  `GET`, `POST` or `DELETE`
     * @param string                $url     absolute URL, query string included
     * @param array<string, string> $headers header name => value
     * @param string|null           $body    the encoded body, or null for none
     * @param float                 $timeout seconds allowed for the whole request
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers = [],
        public readonly ?string $body = null,
        public readonly float $timeout = 120.0,
    ) {}
}
