<?php

declare(strict_types=1);

namespace PhotosSh\Tests\Integration;

use PhotosSh\Exception\ApiException;
use PhotosSh\Exception\NotFoundException;
use PhotosSh\Exception\PhotosException;
use PhotosSh\Exception\UnauthorizedException;
use PhotosSh\Exception\WebhookException;
use PhotosSh\Input;
use PhotosSh\Photos;
use PhotosSh\Tests\Support\LocalServer;
use PhotosSh\Webhook;
use PHPUnit\Framework\TestCase;

/**
 * Against a real engine. Skipped unless PHOTOS_TEST_BASE_URL and
 * PHOTOS_TEST_API_KEY are set. Images are small and model calls few.
 */
final class EngineTest extends TestCase
{
    /** Needs no models. */
    private const RECIPE = "[output]\nformat = \"jpg\"\nmax_side = 64\n";

    private static string $baseUrl = '';

    private static string $apiKey = '';

    private Photos $photos;

    public static function setUpBeforeClass(): void
    {
        self::$baseUrl = (string) getenv('PHOTOS_TEST_BASE_URL');
        self::$apiKey = (string) getenv('PHOTOS_TEST_API_KEY');
    }

    protected function setUp(): void
    {
        if (self::$baseUrl === '' || self::$apiKey === '') {
            self::markTestSkipped('Set PHOTOS_TEST_BASE_URL and PHOTOS_TEST_API_KEY to run the integration tests.');
        }
        // A shared engine with one model worker: be patient when it is busy.
        $this->photos = new Photos(self::$apiKey, self::$baseUrl, maxRetries: 5);
    }

    private static function fixture(string $name): string
    {
        return dirname(__DIR__) . '/fixtures/' . $name;
    }

    /**
     * @return array{int, int, string} width, height, MIME type
     */
    private static function probe(string $bytes): array
    {
        $info = getimagesizefromstring($bytes);
        self::assertIsArray($info, 'the result is an image');

        return [$info[0], $info[1], $info['mime']];
    }

    // --- No models --------------------------------------------------------

    public function testHealth(): void
    {
        self::assertSame('ok', $this->photos->health());
    }

    public function testAccount(): void
    {
        $account = $this->photos->account();

        self::assertArrayHasKey('account', $account);
        self::assertTrue(isset($account['unlimited']) || isset($account['credits']));
    }

    public function testPresets(): void
    {
        $presets = $this->photos->presets();

        self::assertNotEmpty($presets);
        self::assertContains('marketplace', array_column($presets, 'name'));
        self::assertNotSame('', $presets[0]['description']);
    }

    public function testMarketplaces(): void
    {
        $marketplaces = $this->photos->marketplaces();

        self::assertContains('amazon', array_column($marketplaces, 'name'));
        self::assertNotEmpty($marketplaces[0]['rules']);
        self::assertNotEmpty($marketplaces[0]['fix']);
    }

    public function testWebhookSecret(): void
    {
        $secret = $this->photos->webhookSecret();

        self::assertNotSame('', $secret['secret']);
        self::assertSame(Webhook::SIGNATURE_HEADER, $secret['header']);
    }

    public function testResizeFromAPath(): void
    {
        $result = $this->photos->resize(self::fixture('product.png'), width: 100, format: 'jpg', quality: 80);

        self::assertSame('image/jpeg', $result->contentType);
        self::assertSame(100, $result->width);
        self::assertSame(100, $result->height);
        self::assertSame(80, $result->quality);
        self::assertNotNull($result->serverTiming);
        self::assertSame([100, 100, 'image/jpeg'], self::probe($result->bytes));
    }

    public function testResizeFromBytesAndAStreamWithEveryOption(): void
    {
        $bytes = (string) file_get_contents(self::fixture('product.png'));
        $fromBytes = $this->photos->resize(
            Input::fromBytes($bytes),
            width: 400,
            height: 200,
            fit: 'cover',
            enlarge: true,
            format: 'webp',
            quality: 70,
            maxBytes: 50_000,
            background: '#ffffff',
        );
        self::assertSame('image/webp', $fromBytes->contentType);
        self::assertSame([400, 200], [$fromBytes->width, $fromBytes->height]);

        $stream = fopen(self::fixture('product.png'), 'rb');
        self::assertIsResource($stream);
        $fromStream = $this->photos->resize(Input::fromStream($stream), height: 64);
        fclose($stream);
        self::assertSame('image/png', $fromStream->contentType);
        self::assertSame([64, 64], [$fromStream->width, $fromStream->height]);
        self::assertNull($fromStream->quality, 'PNG is lossless: no X-Image-Quality');
    }

    public function testCheckQualityOnly(): void
    {
        $result = $this->photos->check(self::fixture('product.png'));

        self::assertSame(320, $result['metrics']['width'] ?? null);
        self::assertSame(320, $result['metrics']['height'] ?? null);
        self::assertIsArray($result['findings']);
        self::assertArrayNotHasKey('marketplace', $result);
    }

    public function testReadBarcodesFindsNoneInAPlainPhoto(): void
    {
        $result = $this->photos->readBarcodes(self::fixture('product.png'), allFormats: true, engine: 'auto');

        self::assertSame([], $result['barcodes']);
        self::assertIsString($result['variant']);
    }

    public function testRunAPresetAndARecipeWithoutModels(): void
    {
        $preset = $this->photos->run(self::fixture('product.png'), preset: 'web');
        self::assertSame('image/webp', $preset->contentType);
        self::assertSame(320, $preset->width);

        $recipe = $this->photos->run(self::fixture('product.png'), recipe: self::RECIPE);
        self::assertSame([64, 'image/jpeg'], [$recipe->width, $recipe->contentType]);

        try {
            $this->photos->run(self::fixture('product.png'), recipe: 'this is not = = toml');
            self::fail('Expected an ApiException.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertSame(ApiException::UNPROCESSABLE, $e->errorCode);
        }
    }

    public function testResizeTrimsTheBorderAndWritesTheDpi(): void
    {
        $result = $this->photos->resize(self::fixture('product.png'), width: 100, trim: true, trimTolerance: 10, dpi: 300);

        self::assertSame('image/png', $result->contentType);
        self::assertSame(100, $result->width);
        self::assertNotNull($result->height);
        self::assertStringContainsString('pHYs', $result->bytes, 'the print resolution is written into the PNG');
    }

    public function testEnhance(): void
    {
        $result = $this->photos->enhance(
            self::fixture('product.png'),
            brightness: 10,
            contrast: 5,
            sharpen: 20,
            format: 'jpg',
            quality: 85,
        );

        self::assertSame('image/jpeg', $result->contentType);
        self::assertSame([320, 320], [$result->width, $result->height]);
        self::assertSame(85, $result->quality);
    }

    public function testWatermarkWithTextAndWithALogo(): void
    {
        $text = $this->photos->watermark(self::fixture('product.png'), text: 'SAMPLE', position: 'center', opacity: 60);
        self::assertSame([320, 320, 'image/png'], self::probe($text->bytes));

        $logo = $this->photos->watermark(self::fixture('product.png'), watermark: self::fixture('tiny.png'), scale: 20, tile: true);
        self::assertSame([320, 320], [$logo->width, $logo->height]);
        self::assertNotSame($text->bytes, $logo->bytes);
    }

    public function testExpandWithAColourNeedsNoModel(): void
    {
        $result = $this->photos->expand(self::fixture('product.png'), left: 10, right: 30, fill: 'white');

        self::assertSame([360, 320], [$result->width, $result->height]);
    }

    public function testVectorize(): void
    {
        $result = $this->photos->vectorize(self::fixture('tiny.png'), mode: 'color', detail: 4);

        self::assertStringStartsWith('image/svg+xml', $result->contentType);
        self::assertSame('svg', $result->extension());
        self::assertStringContainsString('<svg', $result->bytes);
        self::assertSame([48, 48], [$result->width, $result->height]);
        self::assertGreaterThanOrEqual(1, $result->paths);
        self::assertSame((string) $result->paths, $result->header('X-Paths'));
    }

    public function testPalette(): void
    {
        $result = $this->photos->palette(self::fixture('product.png'), colors: 3);

        self::assertSame([320, 320], [$result['width'], $result['height']]);
        self::assertNotEmpty($result['colors']);
        self::assertLessThanOrEqual(3, count($result['colors']));
        self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $result['colors'][0]['hex']);
        self::assertCount(3, $result['colors'][0]['rgb']);
        self::assertGreaterThan(0, $result['colors'][0]['share']);
    }

    public function testIdPhotos(): void
    {
        $documents = $this->photos->idPhotos();

        self::assertContains('us-passport', array_column($documents, 'name'));
        self::assertNotSame('', $documents[0]['title']);
        self::assertNotEmpty($documents[0]['sources']);
        self::assertGreaterThan(0, $documents[0]['width_mm']);
        self::assertGreaterThan(0, $documents[0]['height_mm']);
        self::assertGreaterThanOrEqual($documents[0]['head_min_mm'], $documents[0]['head_max_mm']);
    }

    public function testErrors(): void
    {
        try {
            $this->photos->resize(Input::fromBytes('this is not an image'), width: 10);
            self::fail('Expected an ApiException.');
        } catch (ApiException $e) {
            self::assertSame(400, $e->status);
            self::assertSame(ApiException::BAD_REQUEST, $e->errorCode);
            self::assertNotSame('', $e->getMessage());
        }

        try {
            $this->photos->resize(self::fixture('product.png'), fit: 'sideways');
            self::fail('Expected an ApiException.');
        } catch (ApiException $e) {
            self::assertSame(ApiException::BAD_REQUEST, $e->errorCode);
            self::assertStringContainsString('fit', $e->getMessage());
        }

        try {
            $this->photos->getJob('job_does_not_exist');
            self::fail('Expected an ApiException.');
        } catch (NotFoundException $e) {
            self::assertSame(404, $e->status);
            self::assertSame(ApiException::NOT_FOUND, $e->errorCode);
        }
    }

    public function testAWrongKeyIsUnauthorized(): void
    {
        $photos = new Photos('not-a-real-key', self::$baseUrl);

        $this->expectException(UnauthorizedException::class);

        $photos->account();
    }

    public function testIdentifyWithoutACatalogueIsAnApiError(): void
    {
        try {
            $result = $this->photos->identify([self::fixture('tiny.png')], top: 3);
            // An engine with a catalogue answers instead.
            self::assertArrayHasKey('candidates', $result);
        } catch (ApiException $e) {
            self::assertSame(503, $e->status);
            self::assertSame(ApiException::MODEL_UNAVAILABLE, $e->errorCode);
        }
    }

    // --- Jobs and webhooks (no models) ------------------------------------

    public function testAJobWithUploadsFromStartToDelete(): void
    {
        $job = $this->photos->createJob(
            'resize',
            [self::fixture('product.png'), Input::fromBytes('not an image', 'broken.png')],
            params: ['width' => 50, 'format' => 'jpg', 'enlarge' => false],
        );
        self::assertStringStartsWith('job_', $job['id']);
        self::assertSame('resize', $job['operation']);
        self::assertSame(2, $job['counts']['total']);
        self::assertSame(['product.png', 'broken.png'], array_column($job['items'], 'input'));

        try {
            $done = $this->photos->waitForJob($job['id'], pollInterval: 0.2, timeout: 60);
            self::assertSame('completed', $done['status']);
            self::assertSame(1, $done['counts']['done']);
            self::assertSame(1, $done['counts']['failed']);
            self::assertSame('done', $done['items'][0]['status']);
            self::assertSame('failed', $done['items'][1]['status']);
            self::assertSame('bad_request', $done['items'][1]['error']['code'] ?? null);
            self::assertArrayHasKey('finished_at', $done);

            $item = $this->photos->jobItemResult($job['id'], 0);
            self::assertTrue($item->isImage());
            self::assertSame('image/jpeg', $item->contentType);
            self::assertSame(50, $item->image()->width);
            self::assertSame([50, 50, 'image/jpeg'], self::probe($item->body));

            self::assertContains($job['id'], array_column($this->photos->listJobs(), 'id'));

            try {
                $this->photos->jobItemResult($job['id'], 1);
                self::fail('Expected a NotFoundException for a failed item.');
            } catch (NotFoundException $e) {
                self::assertSame(404, $e->status);
            }
        } finally {
            $this->photos->deleteJob($job['id']);
        }

        $this->expectException(NotFoundException::class);
        $this->photos->getJob($job['id']);
    }

    public function testAJobWithAnUploadAndARecipe(): void
    {
        $job = $this->photos->createJob('run', [self::fixture('product.png')], recipe: self::RECIPE);

        try {
            $done = $this->photos->waitForJob($job['id'], pollInterval: 0.2, timeout: 60);
            self::assertSame(1, $done['counts']['done'], json_encode($done['items']) ?: '');
            $image = $this->photos->jobItemResult($job['id'], 0)->image();
            self::assertSame([64, 64, 'image/jpeg'], [$image->width, $image->height, $image->contentType]);
        } finally {
            $this->photos->deleteJob($job['id']);
        }
    }

    public function testAVectorizeJobKeepsThePathsOnItsItem(): void
    {
        $job = $this->photos->createJob('vectorize', [self::fixture('tiny.png')], params: ['mode' => 'mono']);

        try {
            $done = $this->photos->waitForJob($job['id'], pollInterval: 0.2, timeout: 60);
            self::assertSame(1, $done['counts']['done'], json_encode($done['items']) ?: '');
            $item = $this->photos->jobItemResult($job['id'], 0);
            self::assertTrue($item->isImage());
            self::assertStringStartsWith('image/svg+xml', $item->contentType);
            if ($item->header('X-Paths') === null) {
                self::markTestSkipped('This engine does not keep X-Paths on job results.');
            }

            $svg = $item->vectorize();
            self::assertSame((string) $svg->paths, $item->header('X-Paths'));
            self::assertStringContainsString('<svg', $svg->bytes);
            self::assertSame('svg', $svg->extension());
            self::assertSame($item->header('X-Paths'), $done['items'][0]['headers']['x-paths'] ?? null);
        } finally {
            $this->photos->deleteJob($job['id']);
        }
    }

    public function testAFixJobKeepsTheVerdictOnItsItem(): void
    {
        $job = $this->photos->createJob('fix', [self::fixture('product.png')], params: ['marketplace' => 'etsy']);

        try {
            $done = $this->photos->waitForJob($job['id'], pollInterval: 0.5, timeout: 300);
            self::assertSame(1, $done['counts']['done'], json_encode($done['items']) ?: '');
            $item = $this->photos->jobItemResult($job['id'], 0);
            if ($item->header('X-Marketplace-Passed') === null) {
                self::markTestSkipped('This engine does not keep X-Marketplace-* on job results.');
            }

            $fixed = $item->fix();
            self::assertSame($item->header('X-Marketplace-Passed') === 'true', $fixed->passed);
            self::assertSame(
                array_values(array_filter(explode(',', $item->header('X-Marketplace-Findings') ?? ''))),
                $fixed->findings,
            );
            self::assertNotNull($fixed->width);
            self::assertSame($item->body, $fixed->bytes);

            $kept = $done['items'][0]['headers'] ?? [];
            self::assertSame($item->header('X-Marketplace-Passed'), $kept['x-marketplace-passed'] ?? null);

            try {
                $item->removeText();
                self::fail('Expected a PhotosException: a fix result has no text counts.');
            } catch (PhotosException $e) {
                self::assertStringContainsString('X-Text-Removed', $e->getMessage());
            }
        } finally {
            $this->photos->deleteJob($job['id']);
        }
    }

    public function testARemoveTextJobKeepsTheCountsOnItsItem(): void
    {
        $job = $this->photos->createJob('remove-text', [self::fixture('text.png')], params: ['region' => 'all']);

        try {
            $done = $this->photos->waitForJob($job['id'], pollInterval: 0.5, timeout: 300);
            self::assertSame(1, $done['counts']['done'], json_encode($done['items']) ?: '');
            $item = $this->photos->jobItemResult($job['id'], 0);
            if ($item->header('X-Text-Removed') === null) {
                self::markTestSkipped('This engine does not keep X-Text-* on job results.');
            }

            $clean = $item->removeText();
            self::assertGreaterThanOrEqual(1, $clean->removed);
            self::assertSame((string) $clean->removed, $item->header('X-Text-Removed'));
            self::assertSame((string) $clean->kept, $item->header('X-Text-Kept'));
            self::assertSame((string) $clean->removed, $done['items'][0]['headers']['x-text-removed'] ?? null);
        } finally {
            $this->photos->deleteJob($job['id']);
        }
    }

    public function testAJobWithAUrlInputAJsonResultAndASignedWebhook(): void
    {
        $server = new LocalServer();
        try {
            $job = $this->photos->createJob(
                'check',
                [$server->url . '/image.png'],
                params: ['subject' => false, 'text' => false],
                webhookUrl: $server->url . '/hook',
            );
            try {
                $done = $this->photos->waitForJob($job['id'], pollInterval: 0.2, timeout: 60);
                self::assertSame(1, $done['counts']['done'], json_encode($done['items']) ?: '');
                self::assertSame('subject=false&text=false', $done['params']);

                $item = $this->photos->jobItemResult($job['id'], 0);
                self::assertTrue($item->isJson());
                self::assertArrayHasKey('metrics', $item->json());

                $delivery = $server->stateDir . '/hook.json';
                $deadline = microtime(true) + 20;
                while (!is_file($delivery) && microtime(true) < $deadline) {
                    usleep(100_000);
                }
                self::assertFileExists($delivery, 'the webhook was delivered');
                $received = json_decode((string) file_get_contents($delivery), true);
                self::assertIsArray($received);
                self::assertIsString($received['signature']);
                self::assertIsString($received['body']);
                $body = (string) base64_decode($received['body'], true);

                $secret = $this->photos->webhookSecret()['secret'];
                $event = Webhook::verify($body, $received['signature'], $secret);
                self::assertSame('job.completed', $event['type']);
                self::assertSame($job['id'], $event['job']['id'] ?? null);

                $this->expectException(WebhookException::class);
                Webhook::verify($body . ' ', $received['signature'], $secret);
            } finally {
                $this->photos->deleteJob($job['id']);
            }
        } finally {
            $server->stop();
        }
    }

    // --- Models: one small call each --------------------------------------

    public function testRemoveBackground(): void
    {
        $result = $this->photos->removeBackground(self::fixture('product.png'), crop: true, padding: 4);

        self::assertSame('image/png', $result->contentType);
        self::assertSame('png', $result->extension());
        self::assertNotNull($result->width);
        self::assertLessThanOrEqual(320, $result->width);
        self::assertSame([$result->width, $result->height, 'image/png'], self::probe($result->bytes));

        $path = sys_get_temp_dir() . '/photos-sh-php-cutout-' . bin2hex(random_bytes(4)) . '.png';
        $result->save($path);
        self::assertSame($result->bytes, file_get_contents($path));
        unlink($path);
    }

    public function testUpscale(): void
    {
        $result = $this->photos->upscale(self::fixture('tiny.png'), scale: 2);

        self::assertSame([96, 96], [$result->width, $result->height]);
        self::assertSame('image/png', $result->contentType);
    }

    public function testEraseWithRectsAndAMask(): void
    {
        $result = $this->photos->erase(
            self::fixture('product.png'),
            rects: [[8, 8, 92, 28], '200,280,40,30'],
            mask: self::fixture('mask.png'),
            format: 'jpg',
            quality: 85,
        );

        self::assertSame('image/jpeg', $result->contentType);
        self::assertSame([320, 320], [$result->width, $result->height]);
        self::assertSame(85, $result->quality);
    }

    public function testReadText(): void
    {
        $result = $this->photos->readText(self::fixture('text.png'));

        self::assertNotEmpty($result['lines']);
        self::assertNotSame('', $result['lines'][0]['text']);
        self::assertCount(4, $result['lines'][0]['box']);
        self::assertGreaterThan(0.5, $result['lines'][0]['score']);
    }

    public function testRemoveText(): void
    {
        $result = $this->photos->removeText(self::fixture('text.png'), region: 'all', format: 'png');

        self::assertSame('image/png', $result->contentType);
        self::assertGreaterThanOrEqual(1, $result->removed);
        self::assertGreaterThanOrEqual(0, $result->kept);
        self::assertSame('' . $result->removed, $result->header('X-Text-Removed'));
    }

    public function testReadLabelFromTwoPhotos(): void
    {
        $result = $this->photos->readLabel([self::fixture('text.png'), Input::fromPath(self::fixture('product.png'))]);

        self::assertIsArray($result['label']);
        self::assertIsArray($result['barcodes']);
        self::assertNotEmpty($result['text']);
    }

    public function testFix(): void
    {
        $result = $this->photos->fix(self::fixture('product.png'), marketplace: 'ebay');

        self::assertNotNull($result->header('X-Marketplace-Passed'));
        self::assertSame($result->header('X-Marketplace-Passed') === 'true', $result->passed);
        self::assertNotNull($result->width);
        if ($result->passed) {
            self::assertNotContains('', $result->findings);
        }
        self::assertSame(
            array_values(array_filter(explode(',', $result->header('X-Marketplace-Findings') ?? ''))),
            $result->findings,
        );
    }

    public function testRemoveBackgroundStagedOnACanvasWithABackgroundImage(): void
    {
        $result = $this->photos->removeBackground(
            self::fixture('product.png'),
            format: 'jpg',
            width: 200,
            height: 240,
            fill: 0.8,
            align: 'bottom',
            shadow: 'contact',
            backgroundImage: self::fixture('tiny.png'),
        );

        self::assertSame('image/jpeg', $result->contentType);
        self::assertSame([200, 240], [$result->width, $result->height]);
        // The header is sent only when there is no subject to place.
        $header = $result->header('X-Subject-Found');
        self::assertSame($header === null ? null : $header === 'true', $result->subjectFound);
    }

    public function testCrop(): void
    {
        $result = $this->photos->crop(self::fixture('product.png'), aspect: '4:5', width: 160, height: 200);

        self::assertSame([160, 200], [$result->width, $result->height]);
        self::assertNotNull($result->header('X-Subject-Found'));
        self::assertSame($result->header('X-Subject-Found') === 'true', $result->subjectFound);
    }

    public function testFindFaces(): void
    {
        $result = $this->photos->findFaces(self::fixture('product.png'), minScore: 0.9);

        self::assertSame([320, 320], [$result['width'], $result['height']]);
        // No fixture has a face: a photo of a bottle should give none.
        self::assertSame([], $result['faces']);
    }

    public function testBlurFaces(): void
    {
        $result = $this->photos->blurFaces(self::fixture('product.png'), mode: 'pixelate', strength: 60);

        self::assertSame([320, 320], [$result->width, $result->height]);
        self::assertNotNull($result->header('X-Faces'));
        self::assertSame((string) $result->faces, $result->header('X-Faces'));
    }

    public function testIdPhotoRefusesAPhotoWithoutAFace(): void
    {
        try {
            $this->photos->idPhoto(self::fixture('product.png'), widthMm: 35, heightMm: 45, headMinMm: 32, headMaxMm: 36);
            self::fail('Expected an ApiException: the photo has no face.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertSame(ApiException::UNPROCESSABLE, $e->errorCode);
        }
    }

    public function testDenoise(): void
    {
        $result = $this->photos->denoise(self::fixture('tiny.png'), strength: 50);

        self::assertSame([48, 48, 'image/png'], [$result->width, $result->height, $result->contentType]);
    }

    public function testColorize(): void
    {
        $result = $this->photos->colorize(self::fixture('tiny.png'), strength: 80);

        self::assertSame([48, 48, 'image/png'], [$result->width, $result->height, $result->contentType]);
    }

    public function testSelectAtAPoint(): void
    {
        $result = $this->photos->select(
            self::fixture('product.png'),
            points: [[160, 130]],
            exclude: [[20, 300]],
            crop: true,
            padding: 2,
        );

        self::assertSame('image/png', $result->contentType);
        self::assertNotNull($result->width);
        self::assertLessThanOrEqual(320, $result->width);
        self::assertSame([$result->width, $result->height, 'image/png'], self::probe($result->bytes));
        self::assertNotNull($result->score);
        self::assertGreaterThan(0.0, $result->score);
        self::assertLessThanOrEqual(1.0, $result->score);
        self::assertSame((float) $result->header('X-Select-Score'), $result->score);
    }

    public function testASelectJobRepeatsItsPointsInAQueryString(): void
    {
        $server = new LocalServer();
        try {
            // A URL input, so the body is JSON and the params go as a string.
            $job = $this->photos->createJob(
                'select',
                [$server->url . '/image.png'],
                params: ['point' => ['160,130', '160,200'], 'exclude' => ['20,300'], 'mask' => true],
            );
            try {
                self::assertSame('point=160%2C130&point=160%2C200&exclude=20%2C300&mask=true', $job['params']);

                $done = $this->photos->waitForJob($job['id'], pollInterval: 0.5, timeout: 300);
                self::assertSame(1, $done['counts']['done'], json_encode($done['items']) ?: '');
                $item = $this->photos->jobItemResult($job['id'], 0);
                if ($item->header('X-Select-Score') === null) {
                    self::markTestSkipped('This engine does not keep X-Select-Score on job results.');
                }

                $mask = $item->select();
                self::assertNotNull($mask->score);
                self::assertSame([320, 320], [$mask->width, $mask->height]);
                self::assertSame($item->header('X-Select-Score'), $done['items'][0]['headers']['x-select-score'] ?? null);
            } finally {
                $this->photos->deleteJob($job['id']);
            }
        } finally {
            $server->stop();
        }
    }

    public function testRestoreFaces(): void
    {
        $result = $this->photos->restoreFaces(self::fixture('product.png'), strength: 80);

        self::assertSame([320, 320], [$result->width, $result->height]);
        self::assertSame((string) $result->faces, $result->header('X-Faces'));
        self::assertSame((string) $result->facesSkipped, $result->header('X-Faces-Skipped'));
    }

    public function testCaption(): void
    {
        $result = $this->photos->caption(self::fixture('product.png'), detail: 'short');

        self::assertNotSame('', trim($result['caption']));
        self::assertSame('short', $result['detail']);
        self::assertSame([320, 320], [$result['width'], $result['height']]);
    }
}
