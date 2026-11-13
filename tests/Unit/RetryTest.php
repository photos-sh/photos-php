<?php

declare(strict_types=1);

namespace PhotosSh\Tests\Unit;

use PhotosSh\Exception\ApiException;
use PhotosSh\Exception\BusyException;
use PhotosSh\Exception\ConnectionException;
use PhotosSh\Exception\TimeoutException;
use PhotosSh\Photos;
use PhotosSh\Tests\Support\ClientTestCase;
use PhotosSh\Tests\Support\FakeTransport;
use PhotosSh\Transport\Response;

final class RetryTest extends ClientTestCase
{
    private static function busy(?string $retryAfter = null): Response
    {
        return FakeTransport::json(
            ['error' => ['code' => 'busy', 'message' => 'all workers are busy; retry shortly']],
            503,
            $retryAfter !== null ? ['Retry-After' => $retryAfter] : [],
        );
    }

    public function testA503IsRetriedAfterRetryAfterSeconds(): void
    {
        $photos = $this->client(self::busy('5'), FakeTransport::image('done'));

        $result = $photos->removeBackground(self::fixture('tiny.png'));

        self::assertSame('done', $result->bytes);
        self::assertCount(2, $this->transport->requests);
        self::assertSame([5.0], $this->sleeps);
        self::assertSame(
            $this->transport->requests[0]->body,
            $this->transport->requests[1]->body,
            'the retry sends the same body',
        );
    }

    public function testRetryAfterIsCappedAtThirtySeconds(): void
    {
        $photos = $this->client(self::busy('120'), FakeTransport::json(['account' => 'a']));

        $photos->account();

        self::assertSame([30.0], $this->sleeps);
    }

    public function testWithoutRetryAfterTheWaitsAreOneThenTwoSeconds(): void
    {
        $photos = $this->client(self::busy(), self::busy(), FakeTransport::json(['account' => 'a']));

        $photos->account();

        self::assertCount(3, $this->transport->requests);
        self::assertSame([1.0, 2.0], $this->sleeps);
    }

    public function testAnHttpDateRetryAfterFallsBackToTheBackoff(): void
    {
        $photos = $this->client(self::busy('Wed, 21 Oct 2026 07:28:00 GMT'), FakeTransport::json(['account' => 'a']));

        $photos->account();

        self::assertSame([1.0], $this->sleeps);
    }

    public function testModelUnavailableIsPermanentAndNotRetried(): void
    {
        $photos = $this->client(
            FakeTransport::error(503, 'model_unavailable', 'this tool is not available on this server'),
            FakeTransport::image(),
        );

        try {
            $photos->upscale(self::fixture('tiny.png'));
            self::fail('Expected an ApiException.');
        } catch (ApiException $e) {
            self::assertSame(ApiException::MODEL_UNAVAILABLE, $e->errorCode);
            self::assertCount(1, $this->transport->requests);
            self::assertSame([], $this->sleeps);
        }
    }

    public function testA503ThatIsNotTheApisJsonErrorIsRetried(): void
    {
        $photos = $this->client(
            new Response(503, ['content-type' => 'text/html'], '<h1>Service Unavailable</h1>'),
            FakeTransport::json(['status' => 'weird'], 503),
            FakeTransport::json(['account' => 'a']),
        );

        $photos->account();

        self::assertCount(3, $this->transport->requests);
        self::assertSame([1.0, 2.0], $this->sleeps);
    }

    public function testGivesUpAfterTwoRetries(): void
    {
        $photos = $this->client(self::busy('5'), self::busy('5'), self::busy('7'));

        try {
            $photos->account();
            self::fail('Expected a BusyException.');
        } catch (BusyException $e) {
            self::assertSame(7, $e->retryAfter);
            self::assertCount(3, $this->transport->requests);
            self::assertSame([5.0, 5.0], $this->sleeps);
        }
    }

    public function testTheNumberOfRetriesIsConfigurable(): void
    {
        $this->transport = new FakeTransport(self::busy(), self::busy(), self::busy(), self::busy());
        $sleeps = [];
        $photos = new Photos(
            self::KEY,
            self::BASE,
            maxRetries: 3,
            transport: $this->transport,
            sleep: static function (float $seconds) use (&$sleeps): void {
                $sleeps[] = $seconds;
            },
        );

        try {
            $photos->account();
            self::fail('Expected a BusyException.');
        } catch (BusyException) {
            self::assertCount(4, $this->transport->requests);
            self::assertSame([1.0, 2.0, 4.0], $sleeps);
        }
    }

    public function testNoRetriesWhenSetToZero(): void
    {
        $this->transport = new FakeTransport(self::busy());
        $photos = new Photos(self::KEY, self::BASE, maxRetries: 0, transport: $this->transport);

        $this->expectException(BusyException::class);

        try {
            $photos->account();
        } finally {
            self::assertCount(1, $this->transport->requests);
        }
    }

    public function testOtherStatusesAreNeverRetried(): void
    {
        foreach ([400, 401, 404, 429, 500, 502, 504] as $status) {
            $photos = $this->client(FakeTransport::error($status, 'busy'));

            try {
                $photos->account();
                self::fail('Expected an ApiException.');
            } catch (ApiException $e) {
                self::assertSame($status, $e->status);
                self::assertCount(1, $this->transport->requests, "status $status");
                self::assertSame([], $this->sleeps);
            }
        }
    }

    public function testAFailedConnectionIsRetried(): void
    {
        $photos = $this->client(
            new ConnectionException('connection refused'),
            new ConnectionException('connection refused'),
            FakeTransport::image('done'),
        );

        $result = $photos->resize(self::fixture('tiny.png'), width: 10);

        self::assertSame('done', $result->bytes);
        self::assertCount(3, $this->transport->requests);
        self::assertSame([1.0, 2.0], $this->sleeps);
    }

    public function testAFailedConnectionIsThrownOnceRetriesAreUsedUp(): void
    {
        $last = new ConnectionException('still refused');
        $photos = $this->client(new ConnectionException('refused'), new ConnectionException('refused'), $last);

        try {
            $photos->account();
            self::fail('Expected a ConnectionException.');
        } catch (ConnectionException $e) {
            self::assertSame($last, $e);
            self::assertCount(3, $this->transport->requests);
        }
    }

    public function testATimeoutIsNotRetried(): void
    {
        $photos = $this->client(new TimeoutException('timed out'), FakeTransport::image());

        try {
            $photos->upscale(self::fixture('tiny.png'));
            self::fail('Expected a TimeoutException.');
        } catch (TimeoutException $e) {
            self::assertInstanceOf(ConnectionException::class, $e);
            self::assertCount(1, $this->transport->requests);
            self::assertSame([], $this->sleeps);
        }
    }

    public function testCreatingAJobIsNotRetriedOnAFailedConnection(): void
    {
        $photos = $this->client(new ConnectionException('reset by peer'), FakeTransport::json(['id' => 'job_1']));

        try {
            $photos->createJob('resize', ['https://example.com/1.jpg']);
            self::fail('Expected a ConnectionException.');
        } catch (ConnectionException) {
            self::assertCount(1, $this->transport->requests);
            self::assertSame([], $this->sleeps);
        }
    }

    public function testCreatingAJobIsRetriedOnA503(): void
    {
        $job = ['id' => 'job_1', 'operation' => 'resize', 'params' => '', 'status' => 'queued', 'created_at' => 1, 'items' => [], 'counts' => []];
        $photos = $this->client(self::busy('3'), FakeTransport::json($job, 202));

        $created = $photos->createJob('resize', ['https://example.com/1.jpg']);

        self::assertSame('job_1', $created['id']);
        self::assertCount(2, $this->transport->requests);
        self::assertSame([3.0], $this->sleeps);
    }
}
