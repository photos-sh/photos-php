<?php

declare(strict_types=1);

namespace PhotosSh\Tests\Unit;

use PhotosSh\Exception\InvalidInputException;
use PhotosSh\Photos;
use PhotosSh\Tests\Support\ClientTestCase;
use PhotosSh\Tests\Support\FakeTransport;

final class ClientTest extends ClientTestCase
{
    protected function tearDown(): void
    {
        putenv('PHOTOS_API_KEY');
        putenv('PHOTOS_BASE_URL');
    }

    public function testSendsTheKeyTheUserAgentAndTheTimeout(): void
    {
        $transport = new FakeTransport(FakeTransport::json(['account' => 'a']));
        $photos = new Photos('secret', 'https://example.test/api', timeout: 7.5, transport: $transport);

        $photos->account();

        $request = $transport->last();
        self::assertSame('GET', $request->method);
        self::assertSame('https://example.test/api/v1/account', $request->url);
        self::assertSame('Bearer secret', $request->headers['Authorization']);
        self::assertSame('photos-sh-php/' . Photos::VERSION, $request->headers['User-Agent']);
        self::assertSame(7.5, $request->timeout);
        self::assertNull($request->body);
        self::assertArrayNotHasKey('Content-Type', $request->headers);
    }

    public function testDefaultsToTheProductionBaseUrlAndTwoMinutes(): void
    {
        $transport = new FakeTransport(FakeTransport::json([]));
        (new Photos('k', transport: $transport))->presets();

        self::assertSame('https://api.photos.sh/v1/presets', $transport->last()->url);
        self::assertSame(120.0, $transport->last()->timeout);
    }

    public function testToleratesATrailingSlashInTheBaseUrl(): void
    {
        $transport = new FakeTransport(FakeTransport::json([]));
        (new Photos('k', 'http://localhost:8080/', transport: $transport))->presets();

        self::assertSame('http://localhost:8080/v1/presets', $transport->last()->url);
    }

    public function testReadsTheKeyAndTheBaseUrlFromTheEnvironment(): void
    {
        putenv('PHOTOS_API_KEY=env-key');
        putenv('PHOTOS_BASE_URL=http://env.test/api/');
        $transport = new FakeTransport(FakeTransport::json([]));

        (new Photos(transport: $transport))->presets();

        self::assertSame('Bearer env-key', $transport->last()->headers['Authorization']);
        self::assertSame('http://env.test/api/v1/presets', $transport->last()->url);
    }

    public function testArgumentsWinOverTheEnvironment(): void
    {
        putenv('PHOTOS_API_KEY=env-key');
        putenv('PHOTOS_BASE_URL=http://env.test/api');
        $transport = new FakeTransport(FakeTransport::json([]));

        (new Photos('arg-key', 'http://arg.test', transport: $transport))->presets();

        self::assertSame('Bearer arg-key', $transport->last()->headers['Authorization']);
        self::assertSame('http://arg.test/v1/presets', $transport->last()->url);
    }

    public function testAMissingKeyIsAnErrorAtConstruction(): void
    {
        putenv('PHOTOS_API_KEY');

        $this->expectException(InvalidInputException::class);
        $this->expectExceptionMessage('PHOTOS_API_KEY');

        new Photos();
    }

    public function testAnEmptyKeyIsAnErrorAtConstruction(): void
    {
        $this->expectException(InvalidInputException::class);

        new Photos('  ');
    }
}
