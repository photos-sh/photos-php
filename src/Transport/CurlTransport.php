<?php

declare(strict_types=1);

namespace PhotosSh\Transport;

use PhotosSh\Exception\ConnectionException;
use PhotosSh\Exception\TimeoutException;

/**
 * The default transport: PHP's cURL extension, one reused handle
 * (keep-alive) per instance.
 */
final class CurlTransport implements Transport
{
    private ?\CurlHandle $handle = null;

    /**
     * @param array<int, mixed> $options extra `CURLOPT_*` options (a proxy, a CA bundle, ...),
     *                                   applied after the transport's own
     */
    public function __construct(private readonly array $options = []) {}

    public function send(Request $request): Response
    {
        if ($this->handle === null) {
            if (!\function_exists('curl_init')) {
                throw new ConnectionException(
                    'The curl extension is not loaded: install it, or pass your own Transport.',
                );
            }
            $handle = curl_init();
            if ($handle === false) {
                throw new ConnectionException('Could not initialise cURL.');
            }
            $this->handle = $handle;
        } else {
            curl_reset($this->handle);
        }
        $handle = $this->handle;

        // "Expect:" stops cURL waiting for "100 Continue" before large bodies.
        $headers = ['Expect:'];
        foreach ($request->headers as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        /** @var array<string, string> $responseHeaders */
        $responseHeaders = [];
        $timeoutMs = max(1, (int) round($request->timeout * 1000));
        $options = [
            \CURLOPT_URL => $request->url,
            \CURLOPT_CUSTOMREQUEST => $request->method,
            \CURLOPT_HTTPHEADER => $headers,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_FOLLOWLOCATION => false,
            \CURLOPT_TIMEOUT_MS => $timeoutMs,
            \CURLOPT_CONNECTTIMEOUT_MS => min($timeoutMs, 10_000),
            \CURLOPT_HEADERFUNCTION => static function (\CurlHandle $handle, string $line) use (&$responseHeaders): int {
                if (str_starts_with($line, 'HTTP/')) {
                    // A new response begins (after "100 Continue", say).
                    $responseHeaders = [];
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $name = strtolower(trim($name));
                    $value = trim($value);
                    $responseHeaders[$name] = isset($responseHeaders[$name])
                        ? $responseHeaders[$name] . ', ' . $value
                        : $value;
                }

                return strlen($line);
            },
        ];
        if ($request->body !== null) {
            $options[\CURLOPT_POSTFIELDS] = $request->body;
        }
        curl_setopt_array($handle, $this->options + $options);

        $body = curl_exec($handle);
        if (!is_string($body)) {
            $errno = curl_errno($handle);
            $message = sprintf('%s %s failed: %s', $request->method, $request->url, curl_error($handle));
            if ($errno === \CURLE_OPERATION_TIMEDOUT) {
                throw new TimeoutException($message, $errno);
            }

            throw new ConnectionException($message, $errno);
        }

        return new Response((int) curl_getinfo($handle, \CURLINFO_RESPONSE_CODE), $responseHeaders, $body);
    }
}
