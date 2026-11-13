<?php

declare(strict_types=1);

namespace PhotosSh\Tests\Transport;

use PhotosSh\Exception\ConnectionException;
use PhotosSh\Exception\TimeoutException;
use PhotosSh\Internal\Multipart;
use PhotosSh\Photos;
use PhotosSh\Tests\Support\LocalServer;
use PhotosSh\Transport\CurlTransport;
use PhotosSh\Transport\Request;
use PHPUnit\Framework\TestCase;

/**
 * The real cURL transport against PHP's built-in web server.
 */
final class CurlTransportTest extends TestCase
{
    private static LocalServer $server;

    public static function setUpBeforeClass(): void
    {
        self::$server = new LocalServer();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $json): array
    {
        $decoded = json_decode($json, true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public function testSendsARequestAndReadsTheResponse(): void
    {
        $transport = new CurlTransport();

        $response = $transport->send(new Request(
            'GET',
            self::$server->url . '/echo?a=1&b=two',
            ['Authorization' => 'Bearer k', 'User-Agent' => 'photos-sh-php/test'],
            null,
            5.0,
        ));

        self::assertSame(200, $response->status);
        self::assertSame('application/json', $response->header('Content-Type'));
        self::assertSame('one', $response->header('x-test'));
        $echo = self::decode($response->body);
        self::assertSame('GET', $echo['method']);
        self::assertSame('a=1&b=two', $echo['query']);
        self::assertSame('Bearer k', $echo['authorization']);
        self::assertSame('photos-sh-php/test', $echo['user_agent']);
    }

    public function testSendsAMultipartBodyARealServerUnderstands(): void
    {
        $image = (string) file_get_contents(dirname(__DIR__) . '/fixtures/product.png');
        $form = new Multipart();
        $form->field('operation', 'resize');
        $form->field('params', 'width=100&background=%23fff');
        $form->file('image', 'product "one".png', $image);
        $form->file('mask', 'mask.png', "\x00\x01\r\n--binary\r\n");

        $response = (new CurlTransport())->send(new Request(
            'POST',
            self::$server->url . '/echo',
            ['Content-Type' => $form->contentType()],
            $form->body(),
            5.0,
        ));

        $echo = self::decode($response->body);
        self::assertSame('POST', $echo['method']);
        self::assertSame(['operation' => 'resize', 'params' => 'width=100&background=%23fff'], $echo['post']);
        self::assertSame(
            [
                'image' => ['name' => 'product %22one%22.png', 'sha1' => sha1($image)],
                'mask' => ['name' => 'mask.png', 'sha1' => sha1("\x00\x01\r\n--binary\r\n")],
            ],
            $echo['files'],
        );
    }

    public function testSendsOtherMethodsAndBodies(): void
    {
        $transport = new CurlTransport();

        $delete = self::decode($transport->send(new Request('DELETE', self::$server->url . '/echo'))->body);
        self::assertSame('DELETE', $delete['method']);

        $post = self::decode($transport->send(new Request(
            'POST',
            self::$server->url . '/echo',
            ['Content-Type' => 'application/json'],
            '{"operation":"ocr"}',
        ))->body);
        self::assertSame('POST', $post['method']);
        self::assertSame('application/json', $post['content_type']);
        self::assertSame(sha1('{"operation":"ocr"}'), $post['body_sha1']);
    }

    public function testAnErrorStatusIsAResponseNotAnException(): void
    {
        $response = (new CurlTransport())->send(new Request('GET', self::$server->url . '/nothing-here'));

        self::assertSame(404, $response->status);
        self::assertSame('', $response->body);
    }

    public function testTheClientRetriesA503OverRealHttp(): void
    {
        $sleeps = [];
        $photos = new Photos(
            'k',
            self::$server->url,
            timeout: 5.0,
            sleep: static function (float $seconds) use (&$sleeps): void {
                $sleeps[] = $seconds;
            },
        );

        $account = $photos->account();

        // The server answered 503 with "Retry-After: 0", then 200.
        self::assertSame(['account' => 'local', 'unlimited' => true, 'calls' => 2], $account);
        self::assertSame([0.0], $sleeps);
    }

    public function testAFailedConnectionIsAConnectionExceptionAndIsRetried(): void
    {
        $sleeps = [];
        $photos = new Photos(
            'k',
            'http://127.0.0.1:' . LocalServer::freePort(),
            timeout: 5.0,
            sleep: static function (float $seconds) use (&$sleeps): void {
                $sleeps[] = $seconds;
            },
        );

        try {
            $photos->account();
            self::fail('Expected a ConnectionException.');
        } catch (ConnectionException $e) {
            self::assertNotInstanceOf(TimeoutException::class, $e);
            self::assertStringContainsString('GET http://127.0.0.1:', $e->getMessage());
            self::assertSame([1.0, 2.0], $sleeps, 'two retries before giving up');
        }
    }

    public function testATimeoutIsATimeoutExceptionAndIsNotRetried(): void
    {
        $sleeps = [];
        $photos = new Photos(
            'k',
            self::$server->url,
            timeout: 0.15,
            sleep: static function (float $seconds) use (&$sleeps): void {
                $sleeps[] = $seconds;
            },
        );

        try {
            // The server takes 0.6 s over this one.
            $photos->presets();
            self::fail('Expected a TimeoutException.');
        } catch (TimeoutException) {
            self::assertSame([], $sleeps);
        }
    }

    public function testExtraCurlOptionsAreApplied(): void
    {
        $transport = new CurlTransport([\CURLOPT_USERAGENT => 'custom-agent/1']);

        $echo = self::decode($transport->send(new Request('GET', self::$server->url . '/echo'))->body);

        self::assertSame('custom-agent/1', $echo['user_agent']);
    }
}
