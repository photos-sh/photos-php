<?php

declare(strict_types=1);

namespace PhotosSh\Tests\Unit;

use PhotosSh\Exception\ApiException;
use PhotosSh\Exception\PhotosException;
use PhotosSh\Tests\Support\ClientTestCase;
use PhotosSh\Tests\Support\FakeTransport;
use PhotosSh\Transport\Response;

final class AccountMethodsTest extends ClientTestCase
{
    public function testAccount(): void
    {
        $body = ['account' => 'acme', 'key' => 'key_1', 'credits' => 950, 'prices' => ['resize' => 1, 'upscale' => 4]];
        $photos = $this->client(FakeTransport::json($body));

        $account = $photos->account();

        self::assertSame('GET', $this->sent()->method);
        self::assertSame('/api/v1/account', $this->sent()->path);
        self::assertSame($body, $account);
    }

    public function testPresets(): void
    {
        $photos = $this->client(FakeTransport::json([['name' => 'web', 'description' => 'Web-ready']]));

        $presets = $photos->presets();

        self::assertSame('/api/v1/presets', $this->sent()->path);
        self::assertSame('web', $presets[0]['name']);
    }

    public function testMarketplaces(): void
    {
        $photos = $this->client(FakeTransport::json([
            ['name' => 'amazon', 'title' => 'Amazon', 'checked' => '2026-11-06', 'sources' => [], 'rules' => [], 'fix' => []],
        ]));

        $marketplaces = $photos->marketplaces();

        self::assertSame('/api/v1/marketplaces', $this->sent()->path);
        self::assertSame('amazon', $marketplaces[0]['name']);
    }

    public function testIdPhotos(): void
    {
        $body = [
            [
                'name' => 'us-passport',
                'title' => 'United States passport',
                'checked' => '2026-11-12',
                'sources' => ['https://travel.state.gov/en/passports/apply/help/photos.html'],
                'notes' => ['Face the camera directly with a neutral expression.'],
                'width_mm' => 50.8,
                'height_mm' => 50.8,
                'head_min_mm' => 25.4,
                'head_max_mm' => 34.9,
                'background' => '#ffffff',
                'alteration' => 'Submit the original, unedited photo without filters or digital changes.',
            ],
            [
                'name' => 'future-card',
                'title' => 'A document added later',
                'checked' => '2027-01-01',
                'sources' => [],
                'notes' => [],
                'width_mm' => 35.5,
                'height_mm' => 45.5,
                'head_min_mm' => 31.5,
                'head_max_mm' => 36.5,
                'eyes_from_bottom_mm' => [28.5, 34.5],
                'background' => '#d9d9d9',
                'future_field' => true,
            ],
        ];
        $photos = $this->client(FakeTransport::json($body));

        $documents = $photos->idPhotos();

        self::assertSame('GET', $this->sent()->method);
        self::assertSame('/api/v1/id-photos', $this->sent()->path);
        self::assertSame('', $this->sent()->query);
        self::assertNull($this->sent()->request->body);
        self::assertSame('Bearer ' . self::KEY, $this->sent()->request->headers['Authorization']);
        self::assertSame('us-passport', $documents[0]['name']);
        self::assertSame(50.8, $documents[0]['width_mm']);
        self::assertArrayNotHasKey('eyes_from_bottom_mm', $documents[0]);
        self::assertSame([28.5, 34.5], $documents[1]['eyes_from_bottom_mm'] ?? null);
        self::assertSame($body, $documents, 'unknown fields are kept');
    }

    public function testWebhookSecret(): void
    {
        $photos = $this->client(FakeTransport::json(['secret' => 'whsec_1', 'header' => 'Photos-Signature', 'scheme' => 's']));

        $secret = $photos->webhookSecret();

        self::assertSame('/api/v1/webhook-secret', $this->sent()->path);
        self::assertSame('whsec_1', $secret['secret']);
    }

    public function testHealthOk(): void
    {
        $photos = $this->client(FakeTransport::json(['status' => 'ok']));

        self::assertSame('ok', $photos->health());
        self::assertSame('GET', $this->sent()->method);
        self::assertSame('/api/health', $this->sent()->path);
    }

    public function testHealthLoadingIsNotAnErrorAndIsNotRetried(): void
    {
        $photos = $this->client(FakeTransport::json(['status' => 'loading'], 503));

        self::assertSame('loading', $photos->health());
        self::assertCount(1, $this->transport->requests);
        self::assertSame([], $this->sleeps);
    }

    public function testHealthFailed(): void
    {
        $photos = $this->client(FakeTransport::json(['status' => 'failed'], 503));

        self::assertSame('failed', $photos->health());
    }

    public function testHealthBehindABrokenProxyIsAnError(): void
    {
        $photos = $this->client(new Response(502, ['content-type' => 'text/html'], '<h1>Bad Gateway</h1>'));

        try {
            $photos->health();
            self::fail('Expected an ApiException.');
        } catch (ApiException $e) {
            self::assertSame(502, $e->status);
            self::assertNull($e->errorCode);
            self::assertSame('<h1>Bad Gateway</h1>', $e->getMessage());
        }
    }

    public function testASuccessThatIsNotJsonIsAnError(): void
    {
        $photos = $this->client(new Response(200, ['content-type' => 'text/html'], '<html>captive portal</html>'));

        $this->expectException(PhotosException::class);
        $this->expectExceptionMessage('captive portal');

        $photos->account();
    }

    public function testAnObjectWhereAListIsExpectedIsAnError(): void
    {
        $photos = $this->client(FakeTransport::json(['not' => 'a list']));

        $this->expectException(PhotosException::class);

        $photos->presets();
    }
}
