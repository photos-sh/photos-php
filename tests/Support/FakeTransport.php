<?php

declare(strict_types=1);

namespace PhotosSh\Tests\Support;

use PhotosSh\Transport\Request;
use PhotosSh\Transport\Response;
use PhotosSh\Transport\Transport;

/**
 * Answers from a queue of responses (or exceptions to throw) and records
 * the requests it was given.
 */
final class FakeTransport implements Transport
{
    /** @var list<Request> */
    public array $requests = [];

    /** @var list<Response|\Throwable> */
    private array $queue;

    public function __construct(Response|\Throwable ...$queue)
    {
        $this->queue = array_values($queue);
    }

    public function send(Request $request): Response
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue);
        if ($next === null) {
            throw new \LogicException('FakeTransport has no response left for ' . $request->method . ' ' . $request->url);
        }
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    public function last(): Request
    {
        $last = end($this->requests);
        if ($last === false) {
            throw new \LogicException('No request was sent.');
        }

        return $last;
    }

    /**
     * @param array<string, string> $headers
     */
    public static function json(mixed $data, int $status = 200, array $headers = []): Response
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json'] + $headers,
            json_encode($data, \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param array<string, string> $headers
     */
    public static function image(string $bytes = "\x89PNG-bytes", array $headers = []): Response
    {
        return new Response(200, $headers + [
            'content-type' => 'image/png',
            'x-image-width' => '640',
            'x-image-height' => '480',
            'server-timing' => 'decode;dur=1.5, model;dur=320.0',
        ], $bytes);
    }

    public static function error(int $status, string $code, string $message = 'nope'): Response
    {
        return self::json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
