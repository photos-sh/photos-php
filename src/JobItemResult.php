<?php

declare(strict_types=1);

namespace PhotosSh;

use PhotosSh\Exception\PhotosException;
use PhotosSh\Transport\Response;

/**
 * The result of one finished job item: what the synchronous endpoint would
 * have returned, an image or JSON depending on the operation.
 */
final class JobItemResult
{
    /** The raw body. */
    public readonly string $body;

    public readonly string $contentType;

    /** @var array<string, string> response headers, names in lower case */
    public readonly array $headers;

    public function __construct(private readonly Response $response)
    {
        $this->body = $response->body;
        $this->contentType = $response->header('Content-Type') ?? 'application/octet-stream';
        $this->headers = $response->headers;
    }

    public function isImage(): bool
    {
        return str_starts_with(strtolower($this->contentType), 'image/');
    }

    public function isJson(): bool
    {
        return str_contains(strtolower($this->contentType), 'json');
    }

    /**
     * The result as an image (operations that return one; the SVG of
     * `vectorize` too). For the counts of `remove-text` use
     * {@see JobItemResult::removeText()}, for the verdict of `fix` use
     * {@see JobItemResult::fix()}. The operations whose synchronous method
     * returns more than the image have an accessor of the same name:
     * {@see JobItemResult::removeBackground()}, {@see JobItemResult::crop()},
     * {@see JobItemResult::vectorize()}, {@see JobItemResult::blurFaces()},
     * {@see JobItemResult::idPhoto()}, {@see JobItemResult::select()},
     * {@see JobItemResult::restoreFaces()}. The image of a `run` item
     * carries what its recipe's reading steps read (`barcodes`, `text`,
     * `textTruncated`), as {@see Photos::run()}'s does.
     *
     * @throws PhotosException when the result is not an image
     */
    public function image(): ImageResult
    {
        if (!$this->isImage()) {
            throw new PhotosException(sprintf('The item result is %s, not an image.', $this->contentType));
        }

        return new ImageResult($this->response);
    }

    /**
     * The result of a `remove-text` item: the image with the counts of lines
     * removed and kept, read from `X-Text-Removed` and `X-Text-Kept` exactly
     * as {@see Photos::removeText()} reads them.
     *
     * @throws PhotosException when the result is not an image, or does not
     *                         carry those headers (the job ran another
     *                         operation, or the engine is too old to keep
     *                         them on job results); it never returns zeros
     *                         for counts it does not know
     */
    public function removeText(): RemoveTextResult
    {
        $this->image();
        $this->need('remove-text', 'X-Text-Removed', 'X-Text-Kept');

        return new RemoveTextResult($this->response);
    }

    /**
     * The result of a `fix` item: the image with the marketplace's verdict,
     * read from `X-Marketplace-Passed` and `X-Marketplace-Findings` exactly
     * as {@see Photos::fix()} reads them.
     *
     * @throws PhotosException when the result is not an image, or does not
     *                         carry `X-Marketplace-Passed` (the job ran
     *                         another operation, or the engine is too old to
     *                         keep it on job results); it never returns a
     *                         verdict it does not know
     */
    public function fix(): FixResult
    {
        $this->image();
        $this->need('fix', 'X-Marketplace-Passed');

        return new FixResult($this->response);
    }

    /**
     * The result of a `remove-background` item, with `subjectFound` read
     * from `X-Subject-Found` exactly as {@see Photos::removeBackground()}
     * reads it: null when the header is absent, as it is unless no subject
     * was found.
     *
     * @throws PhotosException when the result is not an image
     */
    public function removeBackground(): RemoveBackgroundResult
    {
        $this->image();

        return new RemoveBackgroundResult($this->response);
    }

    /**
     * The result of a `crop` item, with `subjectFound` read from
     * `X-Subject-Found` exactly as {@see Photos::crop()} reads it.
     *
     * @throws PhotosException when the result is not an image, or does not
     *                         carry `X-Subject-Found` (the job ran another
     *                         operation, or the engine does not keep it on
     *                         job results)
     */
    public function crop(): CropResult
    {
        $this->image();
        $this->need('crop', 'X-Subject-Found');

        return new CropResult($this->response);
    }

    /**
     * The result of a `vectorize` item: the SVG with its count of paths,
     * read from `X-Paths` exactly as {@see Photos::vectorize()} reads it.
     *
     * @throws PhotosException when the result is not an image, or does not
     *                         carry `X-Paths` (the job ran another
     *                         operation, or the engine does not keep it on
     *                         job results); it never returns a zero for a
     *                         count it does not know
     */
    public function vectorize(): VectorizeResult
    {
        $this->image();
        $this->need('vectorize', 'X-Paths');

        return new VectorizeResult($this->response);
    }

    /**
     * The result of a `blur-faces` item: the image with the count of faces
     * hidden, read from `X-Faces` exactly as {@see Photos::blurFaces()}
     * reads it.
     *
     * @throws PhotosException when the result is not an image, or does not
     *                         carry `X-Faces` (the job ran another
     *                         operation, or the engine does not keep it on
     *                         job results); it never returns a zero for a
     *                         count it does not know
     */
    public function blurFaces(): BlurFacesResult
    {
        $this->image();
        $this->need('blur-faces', 'X-Faces');

        return new BlurFacesResult($this->response);
    }

    /**
     * The result of an `id-photo` item: the image with its findings, read
     * from `X-Id-Photo-Passed`, `X-Id-Photo-Findings` and
     * `X-Id-Photo-Head-Mm` exactly as {@see Photos::idPhoto()} reads them.
     *
     * @throws PhotosException when the result is not an image, or does not
     *                         carry `X-Id-Photo-Passed` (the job ran another
     *                         operation, or the engine does not keep it on
     *                         job results); it never returns a verdict it
     *                         does not know
     */
    public function idPhoto(): IdPhotoResult
    {
        $this->image();
        $this->need('id-photo', 'X-Id-Photo-Passed');

        return new IdPhotoResult($this->response);
    }

    /**
     * The result of a `select` item: the cutout or mask with its score,
     * read from `X-Select-Score` exactly as {@see Photos::select()} reads it.
     *
     * @throws PhotosException when the result is not an image, or does not
     *                         carry `X-Select-Score` (the job ran another
     *                         operation, or the engine does not keep it on
     *                         job results)
     */
    public function select(): SelectResult
    {
        $this->image();
        $this->need('select', 'X-Select-Score');

        return new SelectResult($this->response);
    }

    /**
     * The result of a `restore-faces` item: the image with the counts of
     * faces restored and skipped, read from `X-Faces` and `X-Faces-Skipped`
     * exactly as {@see Photos::restoreFaces()} reads them.
     *
     * @throws PhotosException when the result is not an image, or does not
     *                         carry those headers (the job ran another
     *                         operation, or the engine does not keep them
     *                         on job results); it never returns zeros for
     *                         counts it does not know
     */
    public function restoreFaces(): RestoreFacesResult
    {
        $this->image();
        $this->need('restore-faces', 'X-Faces', 'X-Faces-Skipped');

        return new RestoreFacesResult($this->response);
    }

    /**
     * The decoded JSON result (operations `check`, `barcodes`, `ocr`,
     * `label`, `palette`, `faces`, `caption`).
     *
     * @return array<string, mixed>
     *
     * @throws PhotosException when the result is not a JSON object
     */
    public function json(): array
    {
        $decoded = json_decode($this->body, true);
        if (!is_array($decoded)) {
            throw new PhotosException(sprintf('The item result is %s, not JSON.', $this->contentType));
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * A header's value by case-insensitive name, or null when absent.
     */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * @throws PhotosException when one of the headers is absent
     */
    private function need(string $operation, string ...$headers): void
    {
        foreach ($headers as $header) {
            if ($this->header($header) === null) {
                throw new PhotosException(sprintf(
                    'The item result has no %s header: it is not the result of a %s job, '
                    . 'or the server does not keep that header on job results.',
                    $header,
                    $operation,
                ));
            }
        }
    }

    /**
     * Writes the raw body to a file.
     *
     * @throws PhotosException when the file cannot be written
     */
    public function save(string $path): void
    {
        if (@file_put_contents($path, $this->body) === false) {
            throw new PhotosException(sprintf('Cannot write the result to "%s".', $path));
        }
    }
}
