<?php

declare(strict_types=1);

namespace PhotosSh\Tests\Unit;

use PhotosSh\Exception\AccountSuspendedException;
use PhotosSh\Exception\ApiException;
use PhotosSh\Exception\BusyException;
use PhotosSh\Exception\NotFoundException;
use PhotosSh\Exception\PaymentRequiredException;
use PhotosSh\Exception\PhotosException;
use PhotosSh\Exception\UnauthorizedException;
use PhotosSh\Photos;
use PhotosSh\Tests\Support\ClientTestCase;
use PhotosSh\Tests\Support\FakeTransport;
use PhotosSh\Transport\Response;
use PHPUnit\Framework\Attributes\DataProvider;

final class ErrorsTest extends ClientTestCase
{
    /**
     * @return iterable<string, array{int, string, class-string<ApiException>}>
     */
    public static function codes(): iterable
    {
        yield 'unauthorized' => [401, ApiException::UNAUTHORIZED, UnauthorizedException::class];
        yield 'payment_required' => [402, ApiException::PAYMENT_REQUIRED, PaymentRequiredException::class];
        yield 'account_suspended' => [403, ApiException::ACCOUNT_SUSPENDED, AccountSuspendedException::class];
        yield 'bad_request' => [400, ApiException::BAD_REQUEST, ApiException::class];
        yield 'not_found' => [404, ApiException::NOT_FOUND, NotFoundException::class];
        yield 'payload_too_large' => [413, ApiException::PAYLOAD_TOO_LARGE, ApiException::class];
        yield 'unsupported_format' => [415, ApiException::UNSUPPORTED_FORMAT, ApiException::class];
        yield 'unprocessable' => [422, ApiException::UNPROCESSABLE, ApiException::class];
        yield 'internal' => [500, ApiException::INTERNAL, ApiException::class];
        yield 'a code from the future' => [418, 'teapot', ApiException::class];
    }

    /**
     * @param class-string<ApiException> $class
     */
    #[DataProvider('codes')]
    public function testAnErrorResponseBecomesAnApiException(int $status, string $code, string $class): void
    {
        $photos = $this->client(FakeTransport::error($status, $code, 'what went wrong'));

        try {
            $photos->resize(self::fixture('tiny.png'), width: 10);
            self::fail('Expected an ApiException.');
        } catch (ApiException $e) {
            self::assertSame($class, $e::class);
            self::assertInstanceOf(PhotosException::class, $e);
            self::assertSame($status, $e->status);
            self::assertSame($status, $e->getCode());
            self::assertSame($code, $e->errorCode);
            self::assertSame('what went wrong', $e->getMessage());
            self::assertNull($e->retryAfter);
            self::assertStringContainsString('what went wrong', $e->body);
            self::assertCount(1, $this->transport->requests, 'not retried');
        }
    }

    public function testBusyCarriesRetryAfter(): void
    {
        $busy = FakeTransport::json(
            ['error' => ['code' => 'busy', 'message' => 'all workers are busy; retry shortly']],
            503,
            ['Retry-After' => '5'],
        );
        $this->transport = new FakeTransport($busy);
        $photos = new Photos(self::KEY, self::BASE, maxRetries: 0, transport: $this->transport);

        try {
            $photos->upscale(self::fixture('tiny.png'));
            self::fail('Expected a BusyException.');
        } catch (BusyException $e) {
            self::assertSame(503, $e->status);
            self::assertSame('busy', $e->errorCode);
            self::assertSame(5, $e->retryAfter);
        }
    }

    public function testModelUnavailableIsNotBusy(): void
    {
        $response = FakeTransport::error(503, 'model_unavailable', 'this tool is not available on this server');
        $this->transport = new FakeTransport($response);
        $photos = new Photos(self::KEY, self::BASE, maxRetries: 0, transport: $this->transport);

        try {
            $photos->upscale(self::fixture('tiny.png'));
            self::fail('Expected an ApiException.');
        } catch (ApiException $e) {
            self::assertSame(ApiException::class, $e::class);
            self::assertSame(ApiException::MODEL_UNAVAILABLE, $e->errorCode);
        }
    }

    public function testANonJsonErrorBodyGivesItsStartAsTheMessage(): void
    {
        $html = '<html><body>' . str_repeat('Bad Gateway ', 100) . '</body></html>';
        $photos = $this->client(new Response(502, ['content-type' => 'text/html'], $html));

        try {
            $photos->account();
            self::fail('Expected an ApiException.');
        } catch (ApiException $e) {
            self::assertSame(ApiException::class, $e::class);
            self::assertSame(502, $e->status);
            self::assertNull($e->errorCode);
            self::assertSame(substr($html, 0, 200), $e->getMessage());
            self::assertSame($html, $e->body);
        }
    }

    public function testAnEmptyErrorBodyNamesTheStatus(): void
    {
        $photos = $this->client(new Response(404));

        try {
            $photos->account();
            self::fail('Expected an ApiException.');
        } catch (ApiException $e) {
            self::assertSame(ApiException::class, $e::class, 'an unknown route is a plain API error');
            self::assertSame(404, $e->status);
            self::assertNull($e->errorCode);
            self::assertSame('HTTP 404', $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{int, class-string<ApiException>}>
     */
    public static function statuses(): iterable
    {
        yield '401' => [401, UnauthorizedException::class];
        yield '402' => [402, PaymentRequiredException::class];
        yield '403' => [403, AccountSuspendedException::class];
    }

    /**
     * @param class-string<ApiException> $class
     */
    #[DataProvider('statuses')]
    public function testWithoutACodeTheStatusPicksTheClass(int $status, string $class): void
    {
        $photos = $this->client(new Response($status, [], 'denied by the proxy'));

        try {
            $photos->account();
            self::fail('Expected an ApiException.');
        } catch (ApiException $e) {
            self::assertSame($class, $e::class);
            self::assertNull($e->errorCode);
            self::assertSame('denied by the proxy', $e->getMessage());
        }
    }

    public function testJsonThatIsNotAnErrorObjectStillGivesAnApiException(): void
    {
        $photos = $this->client(FakeTransport::json(['detail' => 'weird'], 400));

        try {
            $photos->account();
            self::fail('Expected an ApiException.');
        } catch (ApiException $e) {
            self::assertNull($e->errorCode);
            self::assertSame('{"detail":"weird"}', $e->getMessage());
        }
    }
}
