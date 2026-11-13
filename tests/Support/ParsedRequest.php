<?php

declare(strict_types=1);

namespace PhotosSh\Tests\Support;

use PhotosSh\Transport\Request;

/**
 * A sent request taken apart for assertions.
 */
final class ParsedRequest
{
    public readonly string $method;

    public readonly string $path;

    /** The raw query string. */
    public readonly string $query;

    /** @var list<array{name: string, filename: string|null, type: string|null, content: string}> */
    public readonly array $parts;

    public function __construct(public readonly Request $request)
    {
        $this->method = $request->method;
        $this->path = (string) parse_url($request->url, \PHP_URL_PATH);
        $this->query = (string) parse_url($request->url, \PHP_URL_QUERY);
        $this->parts = self::multipart($request);
    }

    /**
     * Parts with this name, in order.
     *
     * @return list<array{name: string, filename: string|null, type: string|null, content: string}>
     */
    public function parts(string $name): array
    {
        return array_values(array_filter($this->parts, static fn(array $part): bool => $part['name'] === $name));
    }

    /**
     * @return list<string>
     */
    public function partNames(): array
    {
        return array_column($this->parts, 'name');
    }

    /**
     * @return list<array{name: string, filename: string|null, type: string|null, content: string}>
     */
    private static function multipart(Request $request): array
    {
        $type = $request->headers['Content-Type'] ?? '';
        if (preg_match('#^multipart/form-data; boundary=(.+)$#', $type, $m) !== 1 || $request->body === null) {
            return [];
        }
        $delimiter = '--' . $m[1];
        $body = $request->body;
        $end = "\r\n" . $delimiter . "--\r\n";
        if (!str_starts_with($body, $delimiter . "\r\n") || !str_ends_with($body, $end)) {
            throw new \UnexpectedValueException('The multipart body is not framed by its boundary.');
        }
        $inner = substr($body, strlen($delimiter) + 2, -strlen($end));

        $parts = [];
        foreach (explode("\r\n" . $delimiter . "\r\n", $inner) as $raw) {
            [$head, $content] = explode("\r\n\r\n", $raw, 2);
            if (preg_match('/^Content-Disposition: form-data; name="([^"]*)"(?:; filename="([^"]*)")?\r?$/m', $head, $d) !== 1) {
                throw new \UnexpectedValueException('A multipart part has no Content-Disposition: ' . $head);
            }
            $parts[] = [
                'name' => $d[1],
                'filename' => isset($d[2]) ? $d[2] : null,
                'type' => preg_match('/^Content-Type: (.+)$/m', $head, $t) === 1 ? trim($t[1]) : null,
                'content' => $content,
            ];
        }

        return $parts;
    }
}
