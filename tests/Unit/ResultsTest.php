<?php

declare(strict_types=1);

namespace PhotosSh\Tests\Unit;

use PhotosSh\BlurFacesResult;
use PhotosSh\CropResult;
use PhotosSh\Exception\PhotosException;
use PhotosSh\IdPhotoResult;
use PhotosSh\ImageResult;
use PhotosSh\JobItemResult;
use PhotosSh\RemoveBackgroundResult;
use PhotosSh\RestoreFacesResult;
use PhotosSh\SelectResult;
use PhotosSh\Transport\Response;
use PhotosSh\VectorizeResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResultsTest extends TestCase
{
    public function testImageResultReadsItsHeadersWhateverTheirCase(): void
    {
        $result = new ImageResult(new Response(200, [
            'Content-Type' => 'image/webp',
            'X-Image-Width' => '1600',
            'X-Image-Height' => '1200',
            'X-Image-Quality' => '82',
            'Server-Timing' => 'decode;dur=3',
        ], 'webp-bytes'));

        self::assertSame('webp-bytes', $result->bytes);
        self::assertSame('image/webp', $result->contentType);
        self::assertSame(1600, $result->width);
        self::assertSame(1200, $result->height);
        self::assertSame(82, $result->quality);
        self::assertSame('decode;dur=3', $result->serverTiming);
        self::assertSame('webp', $result->extension());
        self::assertSame('1600', $result->header('x-image-width'));
        self::assertNull($result->header('x-missing'));
    }

    public function testImageResultWithoutHeaders(): void
    {
        $result = new ImageResult(new Response(200, [], 'bytes'));

        self::assertNull($result->width);
        self::assertNull($result->height);
        self::assertNull($result->quality);
        self::assertNull($result->serverTiming);
        self::assertSame('application/octet-stream', $result->contentType);
        self::assertSame('bin', $result->extension());
    }

    public function testSaveWritesTheBytes(): void
    {
        $path = sys_get_temp_dir() . '/photos-sh-save-' . bin2hex(random_bytes(4)) . '.png';
        $result = new ImageResult(new Response(200, ['content-type' => 'image/png'], "\x89PNG\x00data"));

        $result->save($path);

        self::assertSame("\x89PNG\x00data", file_get_contents($path));
        unlink($path);
    }

    public function testSaveFailsLoudly(): void
    {
        $result = new ImageResult(new Response(200, [], 'bytes'));

        $this->expectException(PhotosException::class);

        $result->save('/nonexistent-dir/out.png');
    }

    public function testSubjectFoundIsTrueFalseOrUnknown(): void
    {
        $with = static fn(?string $value): Response => new Response(
            200,
            $value === null ? [] : ['X-Subject-Found' => $value],
        );

        self::assertTrue((new CropResult($with('true')))->subjectFound);
        self::assertFalse((new CropResult($with('false')))->subjectFound);
        self::assertNull((new CropResult($with(null)))->subjectFound, 'absent is unknown, not false');
        self::assertNull((new CropResult($with('')))->subjectFound);
        self::assertNull((new CropResult($with('perhaps')))->subjectFound);

        self::assertFalse((new RemoveBackgroundResult($with('false')))->subjectFound);
        self::assertTrue((new RemoveBackgroundResult($with(' True ')))->subjectFound);
        self::assertNull((new RemoveBackgroundResult($with(null)))->subjectFound, 'absent is unknown, not false');
    }

    public function testVectorizeResult(): void
    {
        $result = new VectorizeResult(new Response(200, [
            'Content-Type' => 'image/svg+xml',
            'X-Image-Width' => '2000',
            'X-Image-Height' => '1500',
            'X-Paths' => '2435',
        ], '<svg/>'));

        self::assertSame(2435, $result->paths);
        self::assertSame('<svg/>', $result->bytes);
        self::assertSame('image/svg+xml', $result->contentType);
        self::assertSame('svg', $result->extension());
        self::assertSame([2000, 1500], [$result->width, $result->height]);

        $path = sys_get_temp_dir() . '/photos-sh-save-' . bin2hex(random_bytes(4)) . '.svg';
        $result->save($path);
        self::assertSame('<svg/>', file_get_contents($path));
        unlink($path);

        self::assertSame(0, (new VectorizeResult(new Response(200, [], '<svg/>')))->paths);
    }

    public function testBlurFacesResult(): void
    {
        self::assertSame(5, (new BlurFacesResult(new Response(200, ['X-Faces' => '5'])))->faces);
        self::assertSame(0, (new BlurFacesResult(new Response(200, ['X-Faces' => '0'])))->faces);
        self::assertSame(0, (new BlurFacesResult(new Response(200)))->faces);
    }

    public function testRestoreFacesResult(): void
    {
        $result = new RestoreFacesResult(new Response(200, ['X-Faces' => '1', 'X-Faces-Skipped' => '2']));
        self::assertSame([1, 2], [$result->faces, $result->facesSkipped]);

        $bare = new RestoreFacesResult(new Response(200));
        self::assertSame([0, 0], [$bare->faces, $bare->facesSkipped]);
    }

    public function testSelectResult(): void
    {
        self::assertSame(0.972, (new SelectResult(new Response(200, ['X-Select-Score' => '0.972'])))->score);
        self::assertSame(1.0, (new SelectResult(new Response(200, ['X-Select-Score' => ' 1 '])))->score);
        self::assertNull((new SelectResult(new Response(200)))->score);
        self::assertNull((new SelectResult(new Response(200, ['X-Select-Score' => 'high'])))->score);
    }

    public function testIdPhotoResult(): void
    {
        $result = new IdPhotoResult(new Response(200, [
            'Content-Type' => 'image/jpeg',
            'X-Image-Width' => '413',
            'X-Image-Height' => '531',
            'X-Id-Photo-Passed' => 'false',
            'X-Id-Photo-Findings' => 'head_tilted, low_resolution',
            'X-Id-Photo-Head-Mm' => '34.0',
        ], 'framed'));

        self::assertFalse($result->passed);
        self::assertSame(['head_tilted', 'low_resolution'], $result->findings);
        self::assertSame(34.0, $result->headMm);
        self::assertSame('framed', $result->bytes);
        self::assertSame(413, $result->width);
    }

    public function testIdPhotoResultWithAnEmptyFindingsHeader(): void
    {
        $result = new IdPhotoResult(new Response(200, [
            'x-id-photo-passed' => 'true',
            'x-id-photo-findings' => '',
            'x-id-photo-head-mm' => '33.4',
        ]));

        self::assertTrue($result->passed);
        self::assertSame([], $result->findings);
        self::assertSame(33.4, $result->headMm);
    }

    public function testIdPhotoResultWithoutItsHeaders(): void
    {
        $result = new IdPhotoResult(new Response(200, ['content-type' => 'image/jpeg']));

        self::assertFalse($result->passed, 'not passed unless the API says so');
        self::assertSame([], $result->findings);
        self::assertNull($result->headMm);
    }

    public function testJobItemResultAsAnImage(): void
    {
        $item = new JobItemResult(new Response(200, [
            'content-type' => 'image/jpeg',
            'x-image-width' => '2000',
            'x-image-height' => '2000',
            'x-image-quality' => '90',
        ], 'jpeg-bytes'));

        self::assertTrue($item->isImage());
        self::assertFalse($item->isJson());
        self::assertSame('jpeg-bytes', $item->body);
        self::assertSame('image/jpeg', $item->contentType);
        self::assertSame('2000', $item->header('X-Image-Width'));
        self::assertSame(2000, $item->image()->width);
        self::assertSame(90, $item->image()->quality);
    }

    public function testJobItemResultOfARemoveTextJob(): void
    {
        $item = new JobItemResult(new Response(200, [
            'content-type' => 'image/png',
            'x-image-width' => '300',
            'x-image-height' => '200',
            'x-text-removed' => '2',
            'x-text-kept' => '0',
        ], 'clean'));

        $result = $item->removeText();

        self::assertSame(2, $result->removed);
        self::assertSame(0, $result->kept);
        self::assertSame('clean', $result->bytes);
        self::assertSame(300, $result->width);
    }

    public function testJobItemResultOfAFixJob(): void
    {
        $item = new JobItemResult(new Response(200, [
            'content-type' => 'image/jpeg',
            'x-image-width' => '2000',
            'x-marketplace-passed' => 'false',
            'x-marketplace-findings' => 'too_small, text_overlay',
        ], 'fixed'));

        $result = $item->fix();

        self::assertFalse($result->passed);
        self::assertSame(['too_small', 'text_overlay'], $result->findings);
        self::assertSame('fixed', $result->bytes);
        self::assertSame(2000, $result->width);
    }

    public function testAFixItemThatPassedHasNoFindings(): void
    {
        $passed = new JobItemResult(new Response(200, [
            'content-type' => 'image/jpeg',
            'x-marketplace-passed' => 'true',
            'x-marketplace-findings' => '',
        ]));
        self::assertTrue($passed->fix()->passed);
        self::assertSame([], $passed->fix()->findings);

        // The findings header may be absent altogether.
        $bare = new JobItemResult(new Response(200, ['content-type' => 'image/jpeg', 'x-marketplace-passed' => 'true']));
        self::assertSame([], $bare->fix()->findings);
    }

    /**
     * @return iterable<string, array{string, array<string, string>, string}>
     */
    public static function itemsWithoutTheHeaders(): iterable
    {
        $image = ['content-type' => 'image/png', 'x-image-width' => '10'];

        yield 'fix on another operation' => ['fix', $image, 'X-Marketplace-Passed'];
        yield 'fix on a remove-text result' => ['fix', $image + ['x-text-removed' => '1', 'x-text-kept' => '0'], 'X-Marketplace-Passed'];
        yield 'removeText on another operation' => ['removeText', $image, 'X-Text-Removed'];
        yield 'removeText with one header only' => ['removeText', $image + ['x-text-removed' => '1'], 'X-Text-Kept'];
        yield 'removeText on a fix result' => ['removeText', $image + ['x-marketplace-passed' => 'true'], 'X-Text-Removed'];
        yield 'fix on JSON' => ['fix', ['content-type' => 'application/json', 'x-marketplace-passed' => 'true'], 'not an image'];
        yield 'removeText on JSON' => ['removeText', ['content-type' => 'application/json'], 'not an image'];

        yield 'crop on another operation' => ['crop', $image, 'X-Subject-Found'];
        yield 'vectorize on another operation' => ['vectorize', $image, 'X-Paths'];
        yield 'blurFaces on another operation' => ['blurFaces', $image, 'X-Faces'];
        yield 'idPhoto on another operation' => ['idPhoto', $image, 'X-Id-Photo-Passed'];
        yield 'idPhoto with the other headers only' => [
            'idPhoto',
            $image + ['x-id-photo-findings' => '', 'x-id-photo-head-mm' => '34.0'],
            'X-Id-Photo-Passed',
        ];
        yield 'select on another operation' => ['select', $image, 'X-Select-Score'];
        yield 'restoreFaces on another operation' => ['restoreFaces', $image, 'X-Faces'];
        yield 'restoreFaces on a blur-faces result' => ['restoreFaces', $image + ['x-faces' => '2'], 'X-Faces-Skipped'];
        yield 'removeBackground on JSON' => ['removeBackground', ['content-type' => 'application/json'], 'not an image'];
        yield 'crop on JSON' => ['crop', ['content-type' => 'application/json', 'x-subject-found' => 'true'], 'not an image'];
        yield 'idPhoto on JSON' => ['idPhoto', ['content-type' => 'application/json', 'x-id-photo-passed' => 'true'], 'not an image'];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('itemsWithoutTheHeaders')]
    public function testTheTypedAccessorsThrowRatherThanGuess(string $method, array $headers, string $message): void
    {
        $item = new JobItemResult(new Response(200, $headers, '{}'));

        $this->expectException(PhotosException::class);
        $this->expectExceptionMessage($message);

        match ($method) {
            'fix' => $item->fix(),
            'removeText' => $item->removeText(),
            'removeBackground' => $item->removeBackground(),
            'crop' => $item->crop(),
            'vectorize' => $item->vectorize(),
            'blurFaces' => $item->blurFaces(),
            'idPhoto' => $item->idPhoto(),
            'select' => $item->select(),
            'restoreFaces' => $item->restoreFaces(),
            default => self::fail('No accessor named ' . $method . '.'),
        };
    }

    /**
     * A finished item whose result is an image with these headers.
     *
     * @param array<string, string> $headers
     */
    private static function imageItem(array $headers = []): JobItemResult
    {
        return new JobItemResult(new Response(
            200,
            $headers + ['content-type' => 'image/png', 'x-image-width' => '300'],
            'bytes',
        ));
    }

    public function testJobItemResultsOfTheEditingTools(): void
    {
        self::assertFalse(self::imageItem(['x-subject-found' => 'false'])->removeBackground()->subjectFound);
        self::assertNull(self::imageItem()->removeBackground()->subjectFound, 'usually absent: unknown');
        self::assertSame(300, self::imageItem()->removeBackground()->width);

        self::assertTrue(self::imageItem(['x-subject-found' => 'true'])->crop()->subjectFound);
        self::assertFalse(self::imageItem(['x-subject-found' => 'false'])->crop()->subjectFound);

        self::assertSame(4, self::imageItem(['x-faces' => '4'])->blurFaces()->faces);
        self::assertSame(0, self::imageItem(['x-faces' => '0'])->blurFaces()->faces);

        $restored = self::imageItem(['x-faces' => '1', 'x-faces-skipped' => '2'])->restoreFaces();
        self::assertSame([1, 2], [$restored->faces, $restored->facesSkipped]);

        $selected = self::imageItem(['x-select-score' => '0.913'])->select();
        self::assertSame(0.913, $selected->score);
        self::assertSame('bytes', $selected->bytes);

        $framed = self::imageItem([
            'x-id-photo-passed' => 'false',
            'x-id-photo-findings' => 'low_resolution',
            'x-id-photo-head-mm' => '34.0',
        ])->idPhoto();
        self::assertFalse($framed->passed);
        self::assertSame(['low_resolution'], $framed->findings);
        self::assertSame(34.0, $framed->headMm);

        // The findings header may be empty, or absent altogether.
        self::assertSame([], self::imageItem(['x-id-photo-passed' => 'true', 'x-id-photo-findings' => ''])->idPhoto()->findings);
        $bare = self::imageItem(['x-id-photo-passed' => 'true'])->idPhoto();
        self::assertTrue($bare->passed);
        self::assertSame([], $bare->findings);
        self::assertNull($bare->headMm);
    }

    public function testJobItemResultOfAVectorizeJob(): void
    {
        $item = new JobItemResult(new Response(200, [
            'content-type' => 'image/svg+xml',
            'x-image-width' => '512',
            'x-image-height' => '512',
            'x-paths' => '23',
        ], '<svg/>'));

        self::assertTrue($item->isImage());
        self::assertFalse($item->isJson());

        $svg = $item->vectorize();
        self::assertSame(23, $svg->paths);
        self::assertSame('<svg/>', $svg->bytes);
        self::assertSame('svg', $svg->extension());
        self::assertSame(512, $svg->width);
        self::assertSame('svg', $item->image()->extension());
    }

    public function testJobItemResultAsJson(): void
    {
        $item = new JobItemResult(new Response(200, ['content-type' => 'application/json'], '{"lines":[{"text":"hi"}]}'));

        self::assertTrue($item->isJson());
        self::assertFalse($item->isImage());
        self::assertSame(['lines' => [['text' => 'hi']]], $item->json());

        $this->expectException(PhotosException::class);
        $item->image();
    }

    public function testAnImageItemIsNotJson(): void
    {
        $item = new JobItemResult(new Response(200, ['content-type' => 'image/png'], "\x89PNG"));

        $this->expectException(PhotosException::class);

        $item->json();
    }

    public function testJobItemResultSave(): void
    {
        $path = sys_get_temp_dir() . '/photos-sh-item-' . bin2hex(random_bytes(4));
        (new JobItemResult(new Response(200, [], 'body')))->save($path);

        self::assertSame('body', file_get_contents($path));
        unlink($path);
    }
}
