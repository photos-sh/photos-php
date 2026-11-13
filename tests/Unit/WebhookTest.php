<?php

declare(strict_types=1);

namespace PhotosSh\Tests\Unit;

use PhotosSh\Exception\PhotosException;
use PhotosSh\Exception\WebhookException;
use PhotosSh\Webhook;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebhookTest extends TestCase
{
    private const SECRET = 'whsec_test';

    private const NOW = 1_800_000_000;

    private const BODY = '{"type":"job.completed","job":{"id":"job_abc","status":"completed","counts":{"total":2,"done":2}}}';

    private static function sign(string $body, int $timestamp, string $secret = self::SECRET): string
    {
        return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    public function testAValidDeliveryGivesTheEvent(): void
    {
        $event = Webhook::verify(self::BODY, self::sign(self::BODY, self::NOW), self::SECRET, now: self::NOW);

        self::assertSame('job.completed', $event['type']);
        self::assertSame('job_abc', $event['job']['id'] ?? null);
    }

    public function testAKnownSignature(): void
    {
        // Fixed vector: HMAC-SHA256("1800000000.{}" , "whsec_test") computed independently below.
        $body = '{"type":"job.completed"}';
        $signature = 't=1800000000,v1=' . hash_hmac('sha256', '1800000000.' . $body, 'whsec_test');

        self::assertSame(['type' => 'job.completed'], Webhook::verify($body, $signature, 'whsec_test', now: self::NOW));
        self::assertSame('Photos-Signature', Webhook::SIGNATURE_HEADER);
    }

    public function testATamperedBodyIsRejected(): void
    {
        $signature = self::sign(self::BODY, self::NOW);

        $this->expectException(WebhookException::class);
        $this->expectExceptionMessage('does not match');

        Webhook::verify(str_replace('job_abc', 'job_xyz', self::BODY), $signature, self::SECRET, now: self::NOW);
    }

    public function testAWrongSecretIsRejected(): void
    {
        $signature = self::sign(self::BODY, self::NOW, 'whsec_other');

        $this->expectException(WebhookException::class);
        $this->expectExceptionMessage('does not match');

        Webhook::verify(self::BODY, $signature, self::SECRET, now: self::NOW);
    }

    public function testATamperedTimestampIsRejected(): void
    {
        $signature = str_replace('t=' . self::NOW, 't=' . (self::NOW + 1), self::sign(self::BODY, self::NOW));

        $this->expectException(WebhookException::class);

        Webhook::verify(self::BODY, $signature, self::SECRET, now: self::NOW);
    }

    public function testAnOldTimestampIsRejected(): void
    {
        $signature = self::sign(self::BODY, self::NOW - 301);

        $this->expectException(WebhookException::class);
        $this->expectExceptionMessage('tolerance');

        Webhook::verify(self::BODY, $signature, self::SECRET, now: self::NOW);
    }

    public function testATimestampFromTheFutureIsRejected(): void
    {
        $signature = self::sign(self::BODY, self::NOW + 301);

        $this->expectException(WebhookException::class);
        $this->expectExceptionMessage('tolerance');

        Webhook::verify(self::BODY, $signature, self::SECRET, now: self::NOW);
    }

    public function testTheEdgeOfTheToleranceIsAccepted(): void
    {
        $event = Webhook::verify(self::BODY, self::sign(self::BODY, self::NOW - 300), self::SECRET, now: self::NOW);

        self::assertSame('job.completed', $event['type']);
    }

    public function testTheToleranceIsConfigurable(): void
    {
        $signature = self::sign(self::BODY, self::NOW - 3000);

        self::assertSame(
            'job.completed',
            Webhook::verify(self::BODY, $signature, self::SECRET, tolerance: 3600, now: self::NOW)['type'],
        );

        $this->expectException(WebhookException::class);
        Webhook::verify(self::BODY, self::sign(self::BODY, self::NOW - 11), self::SECRET, tolerance: 10, now: self::NOW);
    }

    public function testTheClockIsUsedWhenNowIsNotGiven(): void
    {
        $event = Webhook::verify(self::BODY, self::sign(self::BODY, time()), self::SECRET);
        self::assertSame('job.completed', $event['type']);

        $this->expectException(WebhookException::class);
        Webhook::verify(self::BODY, self::sign(self::BODY, time() - 4000), self::SECRET);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedHeaders(): iterable
    {
        $mac = hash_hmac('sha256', self::NOW . '.' . self::BODY, self::SECRET);

        yield 'empty' => [''];
        yield 'garbage' => ['garbage'];
        yield 'no timestamp' => ['v1=' . $mac];
        yield 'no signature' => ['t=' . self::NOW];
        yield 'timestamp not a number' => ['t=yesterday,v1=' . $mac];
        yield 'negative timestamp' => ['t=-5,v1=' . $mac];
        yield 'unknown scheme only' => ['t=' . self::NOW . ',v0=' . $mac];
        yield 'no values' => ['t,v1'];
    }

    #[DataProvider('malformedHeaders')]
    public function testAMalformedHeaderIsRejected(string $header): void
    {
        $this->expectException(WebhookException::class);
        $this->expectExceptionMessage('malformed');

        Webhook::verify(self::BODY, $header, self::SECRET, now: self::NOW);
    }

    public function testSpacesAndUnknownElementsAreTolerated(): void
    {
        $mac = hash_hmac('sha256', self::NOW . '.' . self::BODY, self::SECRET);
        $header = 't=' . self::NOW . ', v2=future, v1=' . strtoupper($mac);

        self::assertSame('job.completed', Webhook::verify(self::BODY, $header, self::SECRET, now: self::NOW)['type']);
    }

    public function testAnyMatchingV1IsEnough(): void
    {
        $mac = hash_hmac('sha256', self::NOW . '.' . self::BODY, self::SECRET);
        $header = 't=' . self::NOW . ',v1=' . str_repeat('0', 64) . ',v1=' . $mac;

        self::assertSame('job.completed', Webhook::verify(self::BODY, $header, self::SECRET, now: self::NOW)['type']);
    }

    public function testASignedBodyThatIsNotAnEventIsRejected(): void
    {
        $this->expectException(WebhookException::class);
        $this->expectExceptionMessage('not a JSON event');

        Webhook::verify('not json', self::sign('not json', self::NOW), self::SECRET, now: self::NOW);
    }

    public function testAnEmptySecretIsRejected(): void
    {
        $this->expectException(WebhookException::class);

        Webhook::verify(self::BODY, self::sign(self::BODY, self::NOW, ''), '', now: self::NOW);
    }

    public function testTheErrorIsALibraryError(): void
    {
        try {
            Webhook::verify(self::BODY, 'nope', self::SECRET);
            self::fail('Expected a WebhookException.');
        } catch (PhotosException $e) {
            self::assertInstanceOf(WebhookException::class, $e);
        }
    }
}
