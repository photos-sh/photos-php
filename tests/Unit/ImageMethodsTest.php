<?php

declare(strict_types=1);

namespace PhotosSh\Tests\Unit;

use PhotosSh\BlurFacesResult;
use PhotosSh\CropResult;
use PhotosSh\Exception\InvalidInputException;
use PhotosSh\FixResult;
use PhotosSh\IdPhotoResult;
use PhotosSh\Input;
use PhotosSh\Photos;
use PhotosSh\RemoveBackgroundResult;
use PhotosSh\RemoveTextResult;
use PhotosSh\RestoreFacesResult;
use PhotosSh\SelectResult;
use PhotosSh\Tests\Support\ClientTestCase;
use PhotosSh\Tests\Support\FakeTransport;
use PhotosSh\Transport\Response;
use PhotosSh\VectorizeResult;
use PHPUnit\Framework\Attributes\DataProvider;

final class ImageMethodsTest extends ClientTestCase
{
    public function testRemoveBackgroundSendsTheImageAndEveryOption(): void
    {
        $photos = $this->client(FakeTransport::image('cutout'));

        $result = $photos->removeBackground(
            self::fixture('product.png'),
            format: 'webp',
            quality: 80,
            background: '#ffffff',
            crop: true,
            padding: 12,
            mask: false,
        );

        $sent = $this->sent();
        self::assertSame('POST', $sent->method);
        self::assertSame('/api/v1/remove-background', $sent->path);
        self::assertSame(
            'format=webp&quality=80&background=%23ffffff&crop=true&padding=12&mask=false',
            $sent->query,
        );
        self::assertSame('Bearer ' . self::KEY, $sent->request->headers['Authorization']);
        self::assertStringStartsWith('multipart/form-data; boundary=', $sent->request->headers['Content-Type']);
        self::assertSame(['image'], $sent->partNames());
        self::assertSame('product.png', $sent->parts[0]['filename']);
        self::assertSame('application/octet-stream', $sent->parts[0]['type']);
        self::assertSame(file_get_contents(self::fixture('product.png')), $sent->parts[0]['content']);

        self::assertInstanceOf(RemoveBackgroundResult::class, $result);
        self::assertSame('cutout', $result->bytes);
        self::assertSame('image/png', $result->contentType);
        self::assertSame(640, $result->width);
        self::assertSame(480, $result->height);
        self::assertNull($result->quality);
        self::assertSame('decode;dur=1.5, model;dur=320.0', $result->serverTiming);
        self::assertNull($result->subjectFound, 'no X-Subject-Found header: unknown, not false');
    }

    public function testRemoveBackgroundStagesTheCutout(): void
    {
        $photos = $this->client(FakeTransport::image('staged', ['x-subject-found' => 'false']));

        $result = $photos->removeBackground(
            self::fixture('product.png'),
            width: 1200,
            height: 1500,
            fill: 0.8,
            align: 'bottom',
            shadow: 'contact',
            reflection: true,
            blur: 40,
        );

        $sent = $this->sent();
        self::assertSame('/api/v1/remove-background', $sent->path);
        self::assertSame(
            'width=1200&height=1500&fill=0.8&align=bottom&shadow=contact&reflection=true&blur=40',
            $sent->query,
        );
        self::assertSame(['image'], $sent->partNames());
        self::assertSame('staged', $result->bytes);
        self::assertFalse($result->subjectFound);
    }

    public function testRemoveBackgroundSendsTheBackgroundImageAsASecondPart(): void
    {
        $photos = $this->client(FakeTransport::image(), FakeTransport::image());

        $photos->removeBackground(
            self::fixture('product.png'),
            width: 800,
            height: 800,
            backgroundImage: Input::fromBytes('backdrop-bytes', 'backdrop.jpg'),
        );

        $sent = $this->sent();
        self::assertSame('width=800&height=800', $sent->query);
        self::assertSame(['image', 'background'], $sent->partNames());
        self::assertSame('product.png', $sent->parts[0]['filename']);
        self::assertSame('backdrop.jpg', $sent->parts[1]['filename']);
        self::assertSame('application/octet-stream', $sent->parts[1]['type']);
        self::assertSame('backdrop-bytes', $sent->parts[1]['content']);

        $photos->removeBackground(self::fixture('product.png'), backgroundImage: self::fixture('tiny.png'));

        $sent = $this->sent();
        self::assertSame('', $sent->query);
        self::assertSame('tiny.png', $sent->parts('background')[0]['filename']);
        self::assertSame(file_get_contents(self::fixture('tiny.png')), $sent->parts('background')[0]['content']);
    }

    public function testOptionsLeftUnsetAreNotSent(): void
    {
        $photos = $this->client(FakeTransport::image());

        $photos->removeBackground(self::fixture('product.png'));

        self::assertSame('', $this->sent()->query);
        self::assertStringEndsWith('/v1/remove-background', $this->sent()->request->url);
    }

    public function testResize(): void
    {
        $photos = $this->client(FakeTransport::image('small', [
            'content-type' => 'image/jpeg',
            'x-image-quality' => '72',
        ]));

        $result = $photos->resize(
            Input::fromBytes('raw-bytes', 'shoe.jpg'),
            width: 800,
            height: 600,
            fit: 'cover',
            enlarge: true,
            format: 'jpg',
            quality: 85,
            maxBytes: 200_000,
            background: 'white',
            trim: true,
            trimTolerance: 12,
            dpi: 300,
        );

        $sent = $this->sent();
        self::assertSame('/api/v1/resize', $sent->path);
        self::assertSame(
            'width=800&height=600&fit=cover&enlarge=true&format=jpg&quality=85&max_bytes=200000&background=white'
            . '&trim=true&trim_tolerance=12&dpi=300',
            $sent->query,
        );
        self::assertSame('shoe.jpg', $sent->parts[0]['filename']);
        self::assertSame('raw-bytes', $sent->parts[0]['content']);
        self::assertSame(72, $result->quality);
        self::assertSame('image/jpeg', $result->contentType);
        self::assertSame('jpg', $result->extension());
    }

    public function testResizeTrimsAndSetsTheDpiOnly(): void
    {
        $photos = $this->client(FakeTransport::image(), FakeTransport::image());

        $photos->resize(self::fixture('product.png'), trim: true);
        self::assertSame('trim=true', $this->sent()->query);

        $photos->resize(self::fixture('product.png'), trim: false, trimTolerance: 0, dpi: 600);
        self::assertSame('trim=false&trim_tolerance=0&dpi=600', $this->sent()->query);
    }

    public function testUpscale(): void
    {
        $photos = $this->client(FakeTransport::image(), FakeTransport::image());

        $photos->upscale(self::fixture('tiny.png'), scale: 2, format: 'png', quality: 90);
        self::assertSame('/api/v1/upscale', $this->sent()->path);
        self::assertSame('scale=2&format=png&quality=90', $this->sent()->query);

        $photos->upscale(self::fixture('tiny.png'), scale: 1.5);
        self::assertSame('scale=1.5', $this->sent()->query);
    }

    public function testEraseRepeatsRectAndSendsTheMask(): void
    {
        $photos = $this->client(FakeTransport::image());

        $photos->erase(
            self::fixture('product.png'),
            rects: [[10, 20, 30, 40], '5,6,7,8'],
            mask: Input::fromBytes('mask-bytes', 'mask.png'),
            format: 'png',
        );

        $sent = $this->sent();
        self::assertSame('/api/v1/erase', $sent->path);
        self::assertSame('rect=10%2C20%2C30%2C40&rect=5%2C6%2C7%2C8&format=png', $sent->query);
        self::assertSame(['image', 'mask'], $sent->partNames());
        self::assertSame('mask.png', $sent->parts[1]['filename']);
        self::assertSame('mask-bytes', $sent->parts[1]['content']);
    }

    public function testEraseWithAMaskPathOnly(): void
    {
        $photos = $this->client(FakeTransport::image());

        $photos->erase(self::fixture('product.png'), mask: self::fixture('mask.png'));

        $sent = $this->sent();
        self::assertSame('', $sent->query);
        self::assertSame(file_get_contents(self::fixture('mask.png')), $sent->parts('mask')[0]['content']);
    }

    public function testRemoveTextReturnsTheCounts(): void
    {
        $photos = $this->client(FakeTransport::image('clean', ['x-text-removed' => '3', 'x-text-kept' => '2']));

        $result = $photos->removeText(
            self::fixture('product.png'),
            region: 'all',
            matching: 'sale|€|www\.',
            format: 'jpg',
            quality: 90,
        );

        $sent = $this->sent();
        self::assertSame('/api/v1/remove-text', $sent->path);
        self::assertSame(
            'region=all&matching=sale%7C%E2%82%AC%7Cwww%5C.&format=jpg&quality=90',
            $sent->query,
        );
        self::assertInstanceOf(RemoveTextResult::class, $result);
        self::assertSame(3, $result->removed);
        self::assertSame(2, $result->kept);
        self::assertSame('clean', $result->bytes);
        self::assertSame(640, $result->width);
    }

    public function testRunWithAPreset(): void
    {
        $photos = $this->client(FakeTransport::image());

        $photos->run(self::fixture('product.png'), preset: 'marketplace');

        $sent = $this->sent();
        self::assertSame('/api/v1/run', $sent->path);
        self::assertSame('preset=marketplace', $sent->query);
        self::assertSame(['image'], $sent->partNames());
    }

    public function testRunWithARecipe(): void
    {
        $photos = $this->client(FakeTransport::image());
        $recipe = "[resize]\nwidth = 100\n";

        $photos->run(self::fixture('product.png'), recipe: $recipe);

        $sent = $this->sent();
        self::assertSame('', $sent->query);
        self::assertSame(['image', 'recipe'], $sent->partNames());
        self::assertSame($recipe, $sent->parts[1]['content']);
        self::assertSame('recipe.toml', $sent->parts[1]['filename']);
    }

    public function testFixReturnsTheVerdict(): void
    {
        $photos = $this->client(FakeTransport::image('fixed', [
            'x-marketplace-passed' => 'false',
            'x-marketplace-findings' => 'too_small,background_not_white',
        ]));

        $result = $photos->fix(self::fixture('product.png'), marketplace: 'amazon');

        self::assertSame('/api/v1/fix', $this->sent()->path);
        self::assertSame('marketplace=amazon', $this->sent()->query);
        self::assertInstanceOf(FixResult::class, $result);
        self::assertFalse($result->passed);
        self::assertSame(['too_small', 'background_not_white'], $result->findings);
        self::assertSame('fixed', $result->bytes);
    }

    public function testFixWithAnEmptyFindingsHeaderGivesAnEmptyList(): void
    {
        $photos = $this->client(FakeTransport::image('fixed', [
            'x-marketplace-passed' => 'true',
            'x-marketplace-findings' => '',
        ]));

        $result = $photos->fix(self::fixture('product.png'), 'ebay');

        self::assertTrue($result->passed);
        self::assertSame([], $result->findings);
    }

    public function testCheck(): void
    {
        $body = [
            'metrics' => ['width' => 320, 'height' => 320, 'sharpness' => 0.8, 'future_metric' => 1],
            'findings' => [['code' => 'too_small', 'severity' => 'error', 'message' => 'Too small']],
            'marketplace' => ['marketplace' => 'amazon', 'passed' => false, 'findings' => [], 'not_checked' => []],
            'future_field' => ['kept' => true],
        ];
        $photos = $this->client(FakeTransport::json($body));

        $result = $photos->check(self::fixture('product.png'), marketplace: 'amazon', subject: true, text: false);

        self::assertSame('/api/v1/check', $this->sent()->path);
        self::assertSame('marketplace=amazon&subject=true&text=false', $this->sent()->query);
        self::assertSame($body, $result, 'unknown fields are kept');
        self::assertSame('too_small', $result['findings'][0]['code']);
    }

    public function testReadBarcodes(): void
    {
        $body = ['barcodes' => [['format' => 'EAN-13', 'value' => '4006381333931', 'gs1' => '4006381333931']], 'variant' => 'plain'];
        $photos = $this->client(FakeTransport::json($body));

        $result = $photos->readBarcodes(self::fixture('product.png'), allFormats: true, engine: 'zbar');

        self::assertSame('/api/v1/barcodes', $this->sent()->path);
        self::assertSame('all_formats=true&engine=zbar', $this->sent()->query);
        self::assertSame('4006381333931', $result['barcodes'][0]['value']);
    }

    public function testReadText(): void
    {
        $body = ['lines' => [['text' => 'SALE', 'score' => 0.98, 'box' => [[1, 2], [3, 2], [3, 4], [1, 4]]]], 'ms' => ['ocr' => 40]];
        $photos = $this->client(FakeTransport::json($body));

        $result = $photos->readText(self::fixture('text.png'));

        self::assertSame('/api/v1/ocr', $this->sent()->path);
        self::assertSame('', $this->sent()->query);
        self::assertSame('SALE', $result['lines'][0]['text']);
    }

    public function testReadLabelSendsEveryImage(): void
    {
        $photos = $this->client(FakeTransport::json(['label' => ['gtin' => '123'], 'barcodes' => [], 'text' => ['a']]));

        $result = $photos->readLabel([self::fixture('product.png'), Input::fromBytes('back', 'back.jpg')]);

        $sent = $this->sent();
        self::assertSame('/api/v1/label', $sent->path);
        self::assertSame(['image', 'image'], $sent->partNames());
        self::assertSame(['product.png', 'back.jpg'], array_column($sent->parts, 'filename'));
        self::assertSame('123', $result['label']['gtin'] ?? null);
    }

    public function testReadLabelTakesASingleImage(): void
    {
        $photos = $this->client(FakeTransport::json(['label' => [], 'barcodes' => [], 'text' => []]));

        $photos->readLabel(self::fixture('product.png'));

        self::assertSame(['image'], $this->sent()->partNames());
    }

    public function testIdentify(): void
    {
        $body = [
            'catalogue' => 'whisky',
            'candidates' => [['product_id' => '42', 'title' => 'Dram', 'score' => 0.9, 'evidence' => ['barcode']]],
            'label' => ['ages' => [12]],
            'barcodes' => ['5000000000000'],
        ];
        $photos = $this->client(FakeTransport::json($body));

        $result = $photos->identify(
            [Input::fromBytes('front', 'front.jpg'), Input::fromBytes('back', 'back.jpg')],
            catalogue: 'whisky',
            top: 3,
        );

        $sent = $this->sent();
        self::assertSame('/api/v1/identify', $sent->path);
        self::assertSame('catalogue=whisky&top=3', $sent->query);
        self::assertSame(['front', 'back'], array_column($sent->parts('image'), 'content'));
        self::assertSame('42', $result['candidates'][0]['product_id']);
    }

    // --- Editing tools ----------------------------------------------------

    /**
     * @return iterable<string, array{string, \Closure(Photos, string): mixed, Response}>
     */
    public static function methodsCalledWithoutOptions(): iterable
    {
        $image = FakeTransport::image();
        $json = FakeTransport::json(['width' => 1, 'height' => 1]);

        yield 'enhance' => ['/v1/enhance', static fn(Photos $p, string $path): mixed => $p->enhance($path), $image];
        yield 'crop' => ['/v1/crop', static fn(Photos $p, string $path): mixed => $p->crop($path), $image];
        yield 'watermark' => ['/v1/watermark', static fn(Photos $p, string $path): mixed => $p->watermark($path), $image];
        yield 'expand' => ['/v1/expand', static fn(Photos $p, string $path): mixed => $p->expand($path), $image];
        yield 'vectorize' => ['/v1/vectorize', static fn(Photos $p, string $path): mixed => $p->vectorize($path), $image];
        yield 'palette' => ['/v1/palette', static fn(Photos $p, string $path): mixed => $p->palette($path), $json];
        yield 'findFaces' => ['/v1/faces', static fn(Photos $p, string $path): mixed => $p->findFaces($path), $json];
        yield 'blurFaces' => ['/v1/blur-faces', static fn(Photos $p, string $path): mixed => $p->blurFaces($path), $image];
        yield 'idPhoto' => ['/v1/id-photo', static fn(Photos $p, string $path): mixed => $p->idPhoto($path), $image];
        yield 'denoise' => ['/v1/denoise', static fn(Photos $p, string $path): mixed => $p->denoise($path), $image];
        yield 'colorize' => ['/v1/colorize', static fn(Photos $p, string $path): mixed => $p->colorize($path), $image];
        yield 'select' => ['/v1/select', static fn(Photos $p, string $path): mixed => $p->select($path), $image];
        yield 'restoreFaces' => ['/v1/restore-faces', static fn(Photos $p, string $path): mixed => $p->restoreFaces($path), $image];
        yield 'caption' => ['/v1/caption', static fn(Photos $p, string $path): mixed => $p->caption($path), $json];
    }

    /**
     * @param \Closure(Photos, string): mixed $call
     */
    #[DataProvider('methodsCalledWithoutOptions')]
    public function testAMethodCalledWithoutOptionsSendsOnlyTheImage(string $path, \Closure $call, Response $response): void
    {
        $photos = $this->client($response);

        $call($photos, self::fixture('product.png'));

        $sent = $this->sent();
        self::assertSame('POST', $sent->method);
        self::assertSame(self::BASE . $path, $sent->request->url, 'options left unset are not sent');
        self::assertSame('Bearer ' . self::KEY, $sent->request->headers['Authorization']);
        self::assertSame(['image'], $sent->partNames());
        self::assertSame('product.png', $sent->parts[0]['filename']);
        self::assertSame(file_get_contents(self::fixture('product.png')), $sent->parts[0]['content']);
    }

    public function testEnhance(): void
    {
        $photos = $this->client(FakeTransport::image('better'));

        $result = $photos->enhance(
            self::fixture('product.png'),
            auto: true,
            levels: false,
            whiteBalance: true,
            brightness: 10,
            contrast: -5.5,
            saturation: 20,
            temperature: -15,
            sharpen: 30,
            format: 'jpg',
            quality: 90,
        );

        $sent = $this->sent();
        self::assertSame('/api/v1/enhance', $sent->path);
        self::assertSame(
            'auto=true&levels=false&white_balance=true&brightness=10&contrast=-5.5&saturation=20'
            . '&temperature=-15&sharpen=30&format=jpg&quality=90',
            $sent->query,
        );
        self::assertSame('better', $result->bytes);
        self::assertSame(640, $result->width);
    }

    public function testCropSaysWhetherASubjectWasFound(): void
    {
        $photos = $this->client(
            FakeTransport::image('cropped', ['x-subject-found' => 'true']),
            FakeTransport::image('centred', ['x-subject-found' => 'false']),
            FakeTransport::image('unknown'),
        );

        $result = $photos->crop(
            self::fixture('product.png'),
            aspect: '4:5',
            width: 800,
            height: 1000,
            zoom: true,
            padding: 0.6,
            focus: 'face',
            format: 'webp',
            quality: 80,
        );

        $sent = $this->sent();
        self::assertSame('/api/v1/crop', $sent->path);
        self::assertSame(
            'aspect=4%3A5&width=800&height=1000&zoom=true&padding=0.6&focus=face&format=webp&quality=80',
            $sent->query,
        );
        self::assertInstanceOf(CropResult::class, $result);
        self::assertTrue($result->subjectFound);
        self::assertSame('cropped', $result->bytes);
        self::assertSame(640, $result->width);

        $centred = $photos->crop(self::fixture('product.png'), aspect: 1.5);
        self::assertSame('aspect=1.5', $this->sent()->query);
        self::assertFalse($centred->subjectFound);

        $unknown = $photos->crop(self::fixture('product.png'), width: 400, height: 400);
        self::assertSame('width=400&height=400', $this->sent()->query);
        self::assertNull($unknown->subjectFound, 'no X-Subject-Found header: unknown, not false');
    }

    public function testWatermarkWithText(): void
    {
        $photos = $this->client(FakeTransport::image('marked'));

        $result = $photos->watermark(
            self::fixture('product.png'),
            text: '© Shop',
            color: '#ff0000',
            position: 'bottom-left',
            scale: 30,
            opacity: 40,
            margin: 2.5,
            tile: false,
            format: 'jpg',
            quality: 85,
        );

        $sent = $this->sent();
        self::assertSame('/api/v1/watermark', $sent->path);
        self::assertSame(
            'text=%C2%A9%20Shop&color=%23ff0000&position=bottom-left&scale=30&opacity=40&margin=2.5&tile=false'
            . '&format=jpg&quality=85',
            $sent->query,
        );
        self::assertSame(['image'], $sent->partNames());
        self::assertSame('marked', $result->bytes);
    }

    public function testWatermarkSendsTheLogoAsASecondPart(): void
    {
        $photos = $this->client(FakeTransport::image(), FakeTransport::image());

        $photos->watermark(
            self::fixture('product.png'),
            watermark: Input::fromBytes('logo-bytes', 'logo.png'),
            position: 'center',
            tile: true,
        );

        $sent = $this->sent();
        self::assertSame('/api/v1/watermark', $sent->path);
        self::assertSame('position=center&tile=true', $sent->query);
        self::assertSame(['image', 'watermark'], $sent->partNames());
        self::assertSame('product.png', $sent->parts[0]['filename']);
        self::assertSame('logo.png', $sent->parts[1]['filename']);
        self::assertSame('application/octet-stream', $sent->parts[1]['type']);
        self::assertSame('logo-bytes', $sent->parts[1]['content']);

        $photos->watermark(self::fixture('product.png'), watermark: self::fixture('tiny.png'));

        $sent = $this->sent();
        self::assertSame('', $sent->query);
        self::assertSame('tiny.png', $sent->parts('watermark')[0]['filename']);
        self::assertSame(file_get_contents(self::fixture('tiny.png')), $sent->parts('watermark')[0]['content']);
    }

    public function testExpand(): void
    {
        $photos = $this->client(FakeTransport::image('wider'), FakeTransport::image());

        $result = $photos->expand(
            self::fixture('product.png'),
            left: 10,
            right: 20,
            top: 0,
            bottom: 40,
            fill: 'white',
            format: 'png',
            quality: 90,
        );

        self::assertSame('/api/v1/expand', $this->sent()->path);
        self::assertSame('left=10&right=20&top=0&bottom=40&fill=white&format=png&quality=90', $this->sent()->query);
        self::assertSame('wider', $result->bytes);

        $photos->expand(self::fixture('product.png'), aspect: '16:9');
        self::assertSame('aspect=16%3A9', $this->sent()->query);
    }

    public function testVectorizeReturnsTheSvgAndItsPaths(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="512" height="512"></svg>';
        $photos = $this->client(new Response(200, [
            'content-type' => 'image/svg+xml',
            'x-image-width' => '512',
            'x-image-height' => '512',
            'x-paths' => '23',
        ], $svg));

        $result = $photos->vectorize(self::fixture('tiny.png'), mode: 'mono', curves: 'polygon', detail: 6, speckle: 8);

        $sent = $this->sent();
        self::assertSame('/api/v1/vectorize', $sent->path);
        self::assertSame('mode=mono&curves=polygon&detail=6&speckle=8', $sent->query);
        self::assertInstanceOf(VectorizeResult::class, $result);
        self::assertSame(23, $result->paths);
        self::assertSame($svg, $result->bytes);
        self::assertSame('image/svg+xml', $result->contentType);
        self::assertSame('svg', $result->extension());
        self::assertSame([512, 512], [$result->width, $result->height]);
        self::assertNull($result->quality);
        self::assertNull($result->serverTiming);
    }

    public function testVectorizeWithoutThePathsHeader(): void
    {
        $photos = $this->client(new Response(200, ['content-type' => 'image/svg+xml'], '<svg/>'));

        self::assertSame(0, $photos->vectorize(self::fixture('tiny.png'))->paths);
    }

    public function testPalette(): void
    {
        $body = [
            'width' => 320,
            'height' => 320,
            'colors' => [
                ['hex' => '#c81018', 'rgb' => [200, 16, 24], 'share' => 0.62],
                ['hex' => '#ffffff', 'rgb' => [255, 255, 255], 'share' => 0.38],
            ],
            'future_field' => ['kept' => true],
        ];
        $photos = $this->client(FakeTransport::json($body));

        $result = $photos->palette(self::fixture('product.png'), colors: 2);

        self::assertSame('/api/v1/palette', $this->sent()->path);
        self::assertSame('colors=2', $this->sent()->query);
        self::assertSame($body, $result, 'unknown fields are kept');
        self::assertSame('#c81018', $result['colors'][0]['hex']);
        self::assertSame([200, 16, 24], $result['colors'][0]['rgb']);
        self::assertSame(0.62, $result['colors'][0]['share']);
    }

    public function testFindFaces(): void
    {
        $body = [
            'width' => 512,
            'height' => 512,
            'faces' => [[
                'box' => ['x' => 180.5, 'y' => 96.0, 'width' => 150.2, 'height' => 190.0],
                'landmarks' => [
                    'right_eye' => [221.4, 170.3],
                    'left_eye' => [290.1, 168.9],
                    'nose' => [256.0, 210.5],
                    'mouth_right' => [228.7, 246.2],
                    'mouth_left' => [284.3, 245.1],
                ],
                'score' => 0.934,
                'roll' => -1.2,
            ]],
        ];
        // As the API writes it: a whole number of pixels is still `96.0`.
        $json = '{"width":512,"height":512,"faces":[{"box":{"x":180.5,"y":96.0,"width":150.2,"height":190.0},'
            . '"landmarks":{"right_eye":[221.4,170.3],"left_eye":[290.1,168.9],"nose":[256.0,210.5],'
            . '"mouth_right":[228.7,246.2],"mouth_left":[284.3,245.1]},"score":0.934,"roll":-1.2}]}';
        $photos = $this->client(
            new Response(200, ['content-type' => 'application/json'], $json),
            FakeTransport::json(['width' => 320, 'height' => 320, 'faces' => []]),
        );

        $result = $photos->findFaces(self::fixture('product.png'), minScore: 0.8);

        self::assertSame('/api/v1/faces', $this->sent()->path);
        self::assertSame('min_score=0.8', $this->sent()->query);
        self::assertSame($body, $result);
        self::assertSame(150.2, $result['faces'][0]['box']['width']);
        self::assertSame([221.4, 170.3], $result['faces'][0]['landmarks']['right_eye']);
        self::assertSame(0.934, $result['faces'][0]['score']);

        self::assertSame([], $photos->findFaces(self::fixture('product.png'))['faces']);
    }

    public function testBlurFacesReturnsHowManyWereHidden(): void
    {
        $photos = $this->client(FakeTransport::image('hidden', ['x-faces' => '3']), FakeTransport::image());

        $result = $photos->blurFaces(
            self::fixture('product.png'),
            mode: 'fill',
            strength: 80,
            padding: 0.25,
            color: 'black',
            minScore: 0.5,
            format: 'jpg',
            quality: 90,
        );

        $sent = $this->sent();
        self::assertSame('/api/v1/blur-faces', $sent->path);
        self::assertSame(
            'mode=fill&strength=80&padding=0.25&color=black&min_score=0.5&format=jpg&quality=90',
            $sent->query,
        );
        self::assertInstanceOf(BlurFacesResult::class, $result);
        self::assertSame(3, $result->faces);
        self::assertSame('hidden', $result->bytes);
        self::assertSame(640, $result->width);

        self::assertSame(0, $photos->blurFaces(self::fixture('product.png'))->faces, 'no X-Faces header');
    }

    public function testIdPhotoReturnsItsFindings(): void
    {
        $photos = $this->client(FakeTransport::image('framed', [
            'content-type' => 'image/jpeg',
            'x-image-width' => '413',
            'x-image-height' => '531',
            'x-id-photo-passed' => 'false',
            'x-id-photo-findings' => 'low_resolution,background_not_plain',
            'x-id-photo-head-mm' => '34.0',
        ]));

        $result = $photos->idPhoto(
            self::fixture('product.png'),
            widthMm: 35,
            heightMm: 45,
            headMinMm: 32,
            headMaxMm: 36,
            eyesMinMm: 28.5,
            eyesMaxMm: 35,
            dpi: 600,
            background: 'white',
            format: 'jpg',
            quality: 95,
        );

        $sent = $this->sent();
        self::assertSame('/api/v1/id-photo', $sent->path);
        self::assertSame(
            'width_mm=35&height_mm=45&head_min_mm=32&head_max_mm=36&eyes_min_mm=28.5&eyes_max_mm=35'
            . '&dpi=600&background=white&format=jpg&quality=95',
            $sent->query,
        );
        self::assertSame(['image'], $sent->partNames());
        self::assertInstanceOf(IdPhotoResult::class, $result);
        self::assertFalse($result->passed);
        self::assertSame(['low_resolution', 'background_not_plain'], $result->findings);
        self::assertSame(34.0, $result->headMm);
        self::assertSame('framed', $result->bytes);
        self::assertSame([413, 531], [$result->width, $result->height]);
        self::assertSame('jpg', $result->extension());
    }

    public function testIdPhotoForADocumentWithAnEmptyFindingsHeader(): void
    {
        $photos = $this->client(FakeTransport::image('framed', [
            'x-id-photo-passed' => 'true',
            'x-id-photo-findings' => '',
            'x-id-photo-head-mm' => '30.5',
        ]));

        $result = $photos->idPhoto(self::fixture('product.png'), document: 'us-passport');

        self::assertSame('document=us-passport', $this->sent()->query);
        self::assertTrue($result->passed);
        self::assertSame([], $result->findings);
        self::assertSame(30.5, $result->headMm);
    }

    public function testIdPhotoWithoutItsHeaders(): void
    {
        $photos = $this->client(FakeTransport::image());

        $result = $photos->idPhoto(self::fixture('product.png'), document: 'uk-passport', background: 'keep');

        self::assertSame('document=uk-passport&background=keep', $this->sent()->query);
        self::assertFalse($result->passed, 'not passed unless the API says so');
        self::assertSame([], $result->findings);
        self::assertNull($result->headMm);
    }

    public function testDenoise(): void
    {
        $photos = $this->client(FakeTransport::image('clean'));

        $result = $photos->denoise(self::fixture('product.png'), strength: 50, format: 'jpg', quality: 92);

        self::assertSame('/api/v1/denoise', $this->sent()->path);
        self::assertSame('strength=50&format=jpg&quality=92', $this->sent()->query);
        self::assertSame('clean', $result->bytes);
    }

    public function testColorize(): void
    {
        $photos = $this->client(FakeTransport::image('coloured'));

        $result = $photos->colorize(self::fixture('product.png'), strength: 62.5, format: 'webp', quality: 80);

        self::assertSame('/api/v1/colorize', $this->sent()->path);
        self::assertSame('strength=62.5&format=webp&quality=80', $this->sent()->query);
        self::assertSame('coloured', $result->bytes);
    }

    public function testSelectRepeatsPointAndExcludeAndSendsTheBox(): void
    {
        $photos = $this->client(FakeTransport::image('object', ['x-select-score' => '0.972']));

        $result = $photos->select(
            self::fixture('product.png'),
            points: [[160, 130.5], '170,200'],
            exclude: [[20, 20], '300,10'],
            box: [100, 30, 120, 260],
            pick: 'largest',
            mask: false,
            crop: true,
            padding: 8,
            background: 'white',
            format: 'webp',
            quality: 80,
        );

        $sent = $this->sent();
        self::assertSame('/api/v1/select', $sent->path);
        self::assertSame(
            'point=160%2C130.5&point=170%2C200&exclude=20%2C20&exclude=300%2C10&box=100%2C30%2C120%2C260'
            . '&pick=largest&mask=false&crop=true&padding=8&background=white&format=webp&quality=80',
            $sent->query,
        );
        self::assertSame(['image'], $sent->partNames());
        self::assertInstanceOf(SelectResult::class, $result);
        self::assertSame(0.972, $result->score);
        self::assertSame('object', $result->bytes);
        self::assertSame(640, $result->width);
    }

    public function testSelectWithOnePointOrABoxOnly(): void
    {
        $photos = $this->client(FakeTransport::image('mask', ['x-select-score' => '1']), FakeTransport::image());

        $mask = $photos->select(self::fixture('product.png'), points: [[160, 130]], mask: true);
        self::assertSame('point=160%2C130&mask=true', $this->sent()->query);
        self::assertSame(1.0, $mask->score);

        $boxed = $photos->select(self::fixture('product.png'), box: '100,30,120.5,260');
        self::assertSame('box=100%2C30%2C120.5%2C260', $this->sent()->query);
        self::assertNull($boxed->score, 'no X-Select-Score header');
    }

    public function testRestoreFacesReturnsTheCounts(): void
    {
        $photos = $this->client(
            FakeTransport::image('restored', ['x-faces' => '2', 'x-faces-skipped' => '1']),
            FakeTransport::image(),
        );

        $result = $photos->restoreFaces(self::fixture('product.png'), strength: 70, minScore: 0.7, format: 'jpg', quality: 90);

        self::assertSame('/api/v1/restore-faces', $this->sent()->path);
        self::assertSame('strength=70&min_score=0.7&format=jpg&quality=90', $this->sent()->query);
        self::assertInstanceOf(RestoreFacesResult::class, $result);
        self::assertSame(2, $result->faces);
        self::assertSame(1, $result->facesSkipped);
        self::assertSame('restored', $result->bytes);

        $bare = $photos->restoreFaces(self::fixture('product.png'));
        self::assertSame([0, 0], [$bare->faces, $bare->facesSkipped], 'no X-Faces headers');
    }

    public function testCaption(): void
    {
        $body = ['caption' => 'A red bottle of jam on a grey background.', 'detail' => 'detailed', 'width' => 320, 'height' => 320];
        $photos = $this->client(FakeTransport::json($body));

        $result = $photos->caption(self::fixture('product.png'), detail: 'detailed');

        self::assertSame('/api/v1/caption', $this->sent()->path);
        self::assertSame('detail=detailed', $this->sent()->query);
        self::assertSame($body, $result);
        self::assertSame('A red bottle of jam on a grey background.', $result['caption']);
    }

    // --- Inputs -----------------------------------------------------------

    public function testMoreThanEightImagesIsRefusedBeforeSending(): void
    {
        $photos = $this->client();

        try {
            $photos->readLabel(array_fill(0, 9, Input::fromBytes('x')));
            self::fail('Expected an InvalidInputException.');
        } catch (InvalidInputException) {
            self::assertSame([], $this->transport->requests);
        }
    }

    public function testNoImagesIsRefusedBeforeSending(): void
    {
        $photos = $this->client();

        $this->expectException(InvalidInputException::class);

        $photos->identify([]);
    }

    public function testAnUnreadableFileIsRefusedBeforeSending(): void
    {
        $photos = $this->client();

        try {
            $photos->resize('/nonexistent/photo.jpg', width: 10);
            self::fail('Expected an InvalidInputException.');
        } catch (InvalidInputException $e) {
            self::assertStringContainsString('/nonexistent/photo.jpg', $e->getMessage());
            self::assertSame([], $this->transport->requests);
        }
    }

    public function testAStreamIsReadFromItsPosition(): void
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, 'stream-bytes');
        rewind($stream);
        $photos = $this->client(FakeTransport::image());

        $photos->resize(Input::fromStream($stream, 'cam.heic'), width: 10);

        self::assertSame('cam.heic', $this->sent()->parts[0]['filename']);
        self::assertSame('stream-bytes', $this->sent()->parts[0]['content']);
        self::assertSame('stream', get_resource_type($stream), 'the stream is left open');
        fclose($stream);
    }

    public function testFileNamesAreMadeSafeForTheHeader(): void
    {
        $photos = $this->client(FakeTransport::image());

        $photos->resize(Input::fromBytes('x', "a\"b\r\nc.png"), width: 10);

        self::assertSame('a%22b%0D%0Ac.png', $this->sent()->parts[0]['filename']);
    }
}
