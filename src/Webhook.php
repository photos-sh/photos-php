<?php

declare(strict_types=1);

namespace PhotosSh;

use PhotosSh\Exception\WebhookException;

/**
 * Verifies webhook deliveries. Needs no client.
 *
 * A delivery carries `Photos-Signature: t=<unix seconds>,v1=<hex>`, where
 * `v1` is HMAC-SHA256, keyed with your webhook secret
 * ({@see Photos::webhookSecret()}), over `"<t>.<raw body>"`.
 */
final class Webhook
{
    /** The header that carries the signature. */
    public const SIGNATURE_HEADER = 'Photos-Signature';

    /** Seconds a delivery's timestamp may differ from now. */
    public const DEFAULT_TOLERANCE = 300;

    /**
     * Checks a delivery's signature and timestamp, and returns the event.
     *
     * @param string   $payload   the raw request body, exactly as received
     * @param string   $signature the `Photos-Signature` header
     * @param string   $secret    your webhook signing key
     * @param int      $tolerance seconds the timestamp may differ from now
     * @param int|null $now       the current Unix time (for tests); null for the clock
     *
     * @return array{type: string, job?: array<string, mixed>, ...<string, mixed>} the event,
     *                                                                           e.g. `['type' => 'job.completed', 'job' => [...]]`
     *
     * @throws WebhookException when the delivery cannot be trusted
     */
    public static function verify(
        string $payload,
        string $signature,
        string $secret,
        int $tolerance = self::DEFAULT_TOLERANCE,
        ?int $now = null,
    ): array {
        if ($secret === '') {
            throw new WebhookException('The webhook secret is empty.');
        }

        $timestamp = null;
        $candidates = [];
        foreach (explode(',', $signature) as $element) {
            $pair = explode('=', trim($element), 2);
            if (count($pair) !== 2) {
                continue;
            }
            [$key, $value] = $pair;
            if ($key === 't' && $timestamp === null) {
                $timestamp = $value;
            } elseif ($key === 'v1') {
                $candidates[] = $value;
            }
        }
        if ($timestamp === null || !ctype_digit($timestamp) || $candidates === []) {
            throw new WebhookException('The Photos-Signature header is malformed: expected "t=<unix seconds>,v1=<hex>".');
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        $matched = false;
        foreach ($candidates as $candidate) {
            // No early exit: every candidate is compared, each in constant time.
            $matched = hash_equals($expected, strtolower($candidate)) || $matched;
        }
        if (!$matched) {
            throw new WebhookException('The webhook signature does not match the body.');
        }

        if (abs(($now ?? time()) - (int) $timestamp) > $tolerance) {
            throw new WebhookException('The webhook timestamp is outside the tolerance.');
        }

        $event = json_decode($payload, true);
        if (!is_array($event) || !is_string($event['type'] ?? null)) {
            throw new WebhookException('The webhook body is not a JSON event.');
        }

        /** @var array{type: string, job?: array<string, mixed>, ...<string, mixed>} $event */
        return $event;
    }
}
