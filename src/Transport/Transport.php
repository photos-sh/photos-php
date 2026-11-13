<?php

declare(strict_types=1);

namespace PhotosSh\Transport;

use PhotosSh\Exception\ConnectionException;

/**
 * Sends one HTTP request. Implement it to use another HTTP client
 * (`wp_remote_request`, Guzzle, ...) in place of the default
 * {@see CurlTransport}.
 *
 * An implementation must not follow redirects, must not retry (the client
 * does), and must return error statuses as a {@see Response}, not throw.
 */
interface Transport
{
    /**
     * @throws ConnectionException when no response was received; a
     *                             {@see \PhotosSh\Exception\TimeoutException}
     *                             when the request timed out
     */
    public function send(Request $request): Response;
}
