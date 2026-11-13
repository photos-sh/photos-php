<?php

declare(strict_types=1);

namespace PhotosSh;

use PhotosSh\Exception\ApiException;
use PhotosSh\Exception\ConnectionException;
use PhotosSh\Exception\InvalidInputException;
use PhotosSh\Exception\PhotosException;
use PhotosSh\Exception\TimeoutException;
use PhotosSh\Exception\WaitTimeoutException;
use PhotosSh\Internal\Multipart;
use PhotosSh\Transport\CurlTransport;
use PhotosSh\Transport\Request;
use PhotosSh\Transport\Response;
use PhotosSh\Transport\Transport;

/**
 * Client for the Photos.sh API.
 *
 * Every method that takes an image accepts a file path (a plain string) or
 * an {@see Input} (bytes, a stream, or a path). Options are named arguments;
 * one left at null is not sent, and the API's default applies.
 *
 * All methods throw {@see PhotosException}: an {@see ApiException} when the
 * API answers with an error, a {@see ConnectionException} when no response
 * arrives, an {@see InvalidInputException} when the call cannot be made.
 *
 * The array shapes below name the fields the API documents; the arrays keep
 * any other field the API returns.
 *
 * @phpstan-type Finding array{code: string, severity: string, message: string, detail?: string}
 * @phpstan-type Verdict array{
 *     marketplace: string,
 *     title?: string,
 *     passed: bool,
 *     findings: list<Finding>,
 *     not_checked: list<string>,
 *     notes?: list<string>,
 *     checked?: string,
 *     sources?: list<string>,
 * }
 * @phpstan-type Metrics array{
 *     width?: int,
 *     height?: int,
 *     sharpness?: float,
 *     noise?: float|null,
 *     brightness?: float,
 *     highlights_clipped?: float,
 *     shadows_clipped?: float,
 *     brightest?: float,
 *     border?: array{mean?: list<float>, white?: float, cast?: list<float>|null},
 *     subject?: array{
 *         x: int,
 *         y: int,
 *         width: int,
 *         height: int,
 *         fill: float,
 *         area: float,
 *         touches: list<string>,
 *         background_white: float,
 *     }|null,
 * }
 * @phpstan-type CheckResult array{
 *     metrics: Metrics,
 *     findings: list<Finding>,
 *     text?: list<array{text: string, on_product: bool|null}>,
 *     marketplace?: Verdict,
 *     ms?: array<string, int>,
 * }
 * @phpstan-type Barcode array{format: string, value: string, gs1?: string|null}
 * @phpstan-type BarcodesResult array{barcodes: list<Barcode>, variant: string}
 * @phpstan-type TextResult array{
 *     lines: list<array{text: string, score: float, box: list<array{float, float}>}>,
 *     ms?: array<string, int>,
 * }
 * @phpstan-type LabelResult array{
 *     label: array<string, mixed>,
 *     barcodes: list<array<string, mixed>>,
 *     text: list<string>,
 *     ms?: array<string, int>,
 * }
 * @phpstan-type IdentifyResult array{
 *     catalogue: string,
 *     candidates: list<array{product_id: string, title: string, score: float, evidence: list<string>}>,
 *     label: array{
 *         ages?: list<int>,
 *         weak_ages?: list<int>,
 *         years?: list<int>,
 *         abvs?: list<float>,
 *         sizes?: list<float>,
 *         quantities?: list<array{value: float, unit: string}>,
 *         packs?: list<int>,
 *         spfs?: list<int>,
 *         strengths?: list<array{value: float, unit: string}>,
 *         counts?: list<int>,
 *         specs?: list<array{value: float, unit: string}>,
 *     },
 *     barcodes: list<string>,
 *     text?: string,
 *     ms?: array<string, int>,
 * }
 * @phpstan-type PaletteResult array{
 *     width: int,
 *     height: int,
 *     colors: list<array{hex: string, rgb: array{int, int, int}, share: float}>,
 * }
 * @phpstan-type Face array{
 *     box: array{x: float, y: float, width: float, height: float},
 *     landmarks: array{
 *         right_eye: array{float, float},
 *         left_eye: array{float, float},
 *         nose: array{float, float},
 *         mouth_right: array{float, float},
 *         mouth_left: array{float, float},
 *     },
 *     score: float,
 *     roll: float,
 * }
 * @phpstan-type FacesResult array{width: int, height: int, faces: list<Face>}
 * @phpstan-type CaptionResult array{caption: string, detail: string, width: int, height: int}
 * @phpstan-type IdPhotoDocument array{
 *     name: string,
 *     title: string,
 *     checked: string,
 *     sources: list<string>,
 *     notes: list<string>,
 *     width_mm: float,
 *     height_mm: float,
 *     head_min_mm: float,
 *     head_max_mm: float,
 *     eyes_from_bottom_mm?: array{float, float},
 *     background: string,
 *     alteration?: string,
 * }
 * @phpstan-type Account array{
 *     account?: string,
 *     key?: string,
 *     credits?: int,
 *     prices?: array<string, int>,
 *     unlimited?: bool,
 * }
 * @phpstan-type Preset array{name: string, description: string}
 * @phpstan-type Marketplace array{
 *     name: string,
 *     title: string,
 *     checked: string,
 *     sources: list<string>,
 *     notes?: list<string>,
 *     rules: list<array<string, mixed>>,
 *     fix: array<string, mixed>,
 * }
 * @phpstan-type JobCounts array{total: int, done: int, failed: int, pending: int}
 * @phpstan-type JobItem array{
 *     input: string,
 *     status: string,
 *     result?: string,
 *     content_type?: string,
 *     bytes?: int,
 *     headers?: array<string, string>,
 *     error?: array{code: string, message: string},
 * }
 * @phpstan-type Job array{
 *     id: string,
 *     operation: string,
 *     params: string,
 *     status: string,
 *     created_at: int,
 *     finished_at?: int,
 *     counts: JobCounts,
 *     items: list<JobItem>,
 *     webhook?: array{url: string, attempts: int, delivered: bool, last_error?: string},
 * }
 * @phpstan-type JobSummary array{
 *     id: string,
 *     operation: string,
 *     status: string,
 *     created_at: int,
 *     finished_at: int|null,
 *     counts: JobCounts,
 * }
 * @phpstan-type WebhookSecret array{secret: string, header: string, scheme: string}
 */
class Photos
{
    public const VERSION = '1.0.0';

    public const DEFAULT_BASE_URL = 'https://api.photos.sh';

    private const RECIPE_FILENAME = 'recipe.toml';

    /** Longest wait the client takes from a `Retry-After` header, in seconds. */
    private const MAX_RETRY_AFTER = 30;

    private readonly string $apiKey;

    private readonly string $baseUrl;

    private readonly Transport $transport;

    /** @var \Closure(float): void */
    private readonly \Closure $sleep;

    /**
     * @param string|null                $apiKey     the API key; defaults to the environment variable `PHOTOS_API_KEY`
     * @param string|null                $baseUrl    defaults to the environment variable `PHOTOS_BASE_URL`,
     *                                               then to `https://api.photos.sh`
     * @param float                      $timeout    seconds allowed for one request
     * @param int                        $maxRetries extra attempts after a busy `503` or a failed connection
     * @param Transport|null             $transport  the HTTP layer; defaults to {@see CurlTransport}
     * @param (\Closure(float): void)|null $sleep    waits the given seconds between attempts and polls (for tests)
     *
     * @throws InvalidInputException when there is no API key
     */
    public function __construct(
        ?string $apiKey = null,
        ?string $baseUrl = null,
        private readonly float $timeout = 120.0,
        private readonly int $maxRetries = 2,
        ?Transport $transport = null,
        ?\Closure $sleep = null,
    ) {
        $apiKey ??= self::env('PHOTOS_API_KEY');
        if ($apiKey === null || trim($apiKey) === '') {
            throw new InvalidInputException(
                'No API key: pass one to the Photos constructor or set the PHOTOS_API_KEY environment variable.',
            );
        }
        $this->apiKey = trim($apiKey);
        $this->baseUrl = rtrim($baseUrl ?? self::env('PHOTOS_BASE_URL') ?? self::DEFAULT_BASE_URL, '/');
        $this->transport = $transport ?? new CurlTransport();
        $this->sleep = $sleep ?? static function (float $seconds): void {
            if ($seconds > 0) {
                usleep((int) round($seconds * 1_000_000));
            }
        };
    }

    // --- Edit -------------------------------------------------------------

    /**
     * Removes the background. The options from `width` on stage the cutout
     * in the same call: a canvas, a shadow or reflection under the subject,
     * and something behind it. Give at most one of `background`,
     * `backgroundImage` and `blur`.
     *
     * @param string|null       $format          output format: `png` (the default), `jpg`, `webp`, `avif`
     * @param int|null          $quality         1-100, for lossy formats
     * @param string|null       $background      composite onto a colour (`white`, `#rrggbb`); omit for transparency
     * @param bool|null         $crop            crop to the subject; not with a canvas or `blur`
     * @param int|null          $padding         margin around the subject when cropping, in pixels
     * @param bool|null         $mask            return the greyscale alpha mask instead of the cutout;
     *                                           takes none of the options from `width` on
     * @param int|null          $width           place the subject on a canvas this wide; needs `height`
     * @param int|null          $height          the canvas's height; needs `width`
     * @param int|float|null    $fill            how much of the canvas the subject may take in its tighter
     *                                           dimension, 0.05 to 1 (0.85 by default); needs a canvas
     * @param string|null       $align           where the subject sits on the canvas: `center` (the default),
     *                                           `top` or `bottom`
     * @param string|null       $shadow          `none` (the default), `contact` (at the subject's base) or `drop`
     * @param bool|null         $reflection      a fading mirror image under the subject; leave room below it
     * @param int|float|null    $blur            keep the photo's own background, blurred: 1 (slight) to 100
     * @param string|Input|null $backgroundImage an image to put behind the subject, scaled to cover the result
     */
    public function removeBackground(
        string|Input $image,
        ?string $format = null,
        ?int $quality = null,
        ?string $background = null,
        ?bool $crop = null,
        ?int $padding = null,
        ?bool $mask = null,
        ?int $width = null,
        ?int $height = null,
        int|float|null $fill = null,
        ?string $align = null,
        ?string $shadow = null,
        ?bool $reflection = null,
        int|float|null $blur = null,
        string|Input|null $backgroundImage = null,
    ): RemoveBackgroundResult {
        $extra = $backgroundImage !== null ? ['background' => Input::from($backgroundImage)] : [];

        return new RemoveBackgroundResult($this->postImages('/v1/remove-background', [$image], [
            'format' => $format,
            'quality' => $quality,
            'background' => $background,
            'crop' => $crop,
            'padding' => $padding,
            'mask' => $mask,
            'width' => $width,
            'height' => $height,
            'fill' => $fill,
            'align' => $align,
            'shadow' => $shadow,
            'reflection' => $reflection,
            'blur' => $blur,
        ], $extra));
    }

    /**
     * Resizes, converts and compresses.
     *
     * @param string|null $fit           `contain` (the default), `cover` or `fill`; the last two need both sides
     * @param bool|null   $enlarge       allow scaling up with `contain`
     * @param string|null $format        output format; defaults to the photo's own
     * @param int|null    $quality       1-100, for lossy formats
     * @param int|null    $maxBytes      lower the quality until the file fits
     * @param string|null $background    flatten transparency onto this colour
     * @param bool|null   $trim          cut away an even border first: whatever matches the top-left pixel
     * @param int|null    $trimTolerance how far a pixel may differ from the border's colour and still be
     *                                   border, per channel, 0-255 (10 by default); needs `trim`
     * @param int|null    $dpi           print resolution to write into a JPEG or PNG, 1-2400; no pixel changes
     */
    public function resize(
        string|Input $image,
        ?int $width = null,
        ?int $height = null,
        ?string $fit = null,
        ?bool $enlarge = null,
        ?string $format = null,
        ?int $quality = null,
        ?int $maxBytes = null,
        ?string $background = null,
        ?bool $trim = null,
        ?int $trimTolerance = null,
        ?int $dpi = null,
    ): ImageResult {
        return new ImageResult($this->postImages('/v1/resize', [$image], [
            'width' => $width,
            'height' => $height,
            'fit' => $fit,
            'enlarge' => $enlarge,
            'format' => $format,
            'quality' => $quality,
            'max_bytes' => $maxBytes,
            'background' => $background,
            'trim' => $trim,
            'trim_tolerance' => $trimTolerance,
            'dpi' => $dpi,
        ]));
    }

    /**
     * Upscales with AI.
     *
     * @param int|float|null $scale 1 to 4 (the default)
     */
    public function upscale(
        string|Input $image,
        int|float|null $scale = null,
        ?string $format = null,
        ?int $quality = null,
    ): ImageResult {
        return new ImageResult($this->postImages('/v1/upscale', [$image], [
            'scale' => $scale,
            'format' => $format,
            'quality' => $quality,
        ]));
    }

    /**
     * Removes objects by inpainting. Give the area as rectangles, a mask, or both.
     *
     * @param list<array{int, int, int, int}|string> $rects areas to remove, each `[x, y, width, height]`
     *                                                      (or the string `"x,y,width,height"`)
     * @param string|Input|null                      $mask  an image the size of the photo; white = remove
     */
    public function erase(
        string|Input $image,
        array $rects = [],
        string|Input|null $mask = null,
        ?string $format = null,
        ?int $quality = null,
    ): ImageResult {
        $extra = $mask !== null ? ['mask' => Input::from($mask)] : [];

        return new ImageResult($this->postImages('/v1/erase', [$image], [
            'rect' => array_map(self::numbers(...), $rects),
            'format' => $format,
            'quality' => $quality,
        ], $extra));
    }

    /**
     * Removes text, watermarks and captions.
     *
     * @param string|null $region   `background` (the default: only text off the product) or `all`
     * @param string|null $matching remove only lines matching this case-insensitive regular expression
     */
    public function removeText(
        string|Input $image,
        ?string $region = null,
        ?string $matching = null,
        ?string $format = null,
        ?int $quality = null,
    ): RemoveTextResult {
        return new RemoveTextResult($this->postImages('/v1/remove-text', [$image], [
            'region' => $region,
            'matching' => $matching,
            'format' => $format,
            'quality' => $quality,
        ]));
    }

    /**
     * Fixes light and colour. Without settings it stretches the tones to the
     * full range and takes out a colour cast. With any setting, only what is
     * given is applied (add `auto: true` to keep the automatic part).
     *
     * @param bool|null      $auto         automatic levels and white balance; on when no other setting is given
     * @param bool|null      $levels       stretch the tones so the darkest pixels are black and the brightest white
     * @param bool|null      $whiteBalance take out a colour cast
     * @param int|float|null $brightness   -100 to 100
     * @param int|float|null $contrast     -100 to 100
     * @param int|float|null $saturation   -100 (black and white) to 100
     * @param int|float|null $temperature  -100 (cooler, bluer) to 100 (warmer)
     * @param int|float|null $sharpen      0 to 100
     */
    public function enhance(
        string|Input $image,
        ?bool $auto = null,
        ?bool $levels = null,
        ?bool $whiteBalance = null,
        int|float|null $brightness = null,
        int|float|null $contrast = null,
        int|float|null $saturation = null,
        int|float|null $temperature = null,
        int|float|null $sharpen = null,
        ?string $format = null,
        ?int $quality = null,
    ): ImageResult {
        return new ImageResult($this->postImages('/v1/enhance', [$image], [
            'auto' => $auto,
            'levels' => $levels,
            'white_balance' => $whiteBalance,
            'brightness' => $brightness,
            'contrast' => $contrast,
            'saturation' => $saturation,
            'temperature' => $temperature,
            'sharpen' => $sharpen,
            'format' => $format,
            'quality' => $quality,
        ]));
    }

    /**
     * Crops to a shape around the subject, keeping the background. Without
     * `zoom` the crop is the largest of that shape, moved to hold the
     * subject; with it, the smallest that holds the subject and its padding.
     *
     * @param string|int|float|null $aspect  the shape as width:height (`4:5`, `16:9`) or a number (`1.5`)
     * @param int|null              $width   scale the crop to this width; needs `height`. Without `aspect`
     *                                       the two also give the shape
     * @param int|null              $height  scale the crop to this height; needs `width`
     * @param bool|null             $zoom    crop tightly around the subject
     * @param int|float|null        $padding room around the subject with `zoom`, as a fraction of its longest
     *                                       side, 0 to 2 (0.1 by default)
     * @param string|null           $focus   what the crop is built around: `subject` (the default) or `face`
     *                                       (all the faces in the photo, as one area)
     */
    public function crop(
        string|Input $image,
        string|int|float|null $aspect = null,
        ?int $width = null,
        ?int $height = null,
        ?bool $zoom = null,
        int|float|null $padding = null,
        ?string $focus = null,
        ?string $format = null,
        ?int $quality = null,
    ): CropResult {
        return new CropResult($this->postImages('/v1/crop', [$image], [
            'aspect' => $aspect,
            'width' => $width,
            'height' => $height,
            'zoom' => $zoom,
            'padding' => $padding,
            'focus' => $focus,
            'format' => $format,
            'quality' => $quality,
        ]));
    }

    /**
     * Adds a logo or text. Give `text` or a `watermark` image, not both.
     *
     * @param string|null       $text      up to 200 characters, drawn in a bold sans-serif with Latin, Greek
     *                                     and Cyrillic letters
     * @param string|Input|null $watermark the logo; a PNG with transparency works best
     * @param string|null       $color     the text's colour: `white` (the default), `black`, `#rrggbb`
     * @param string|null       $position  `top-left`, `top`, `top-right`, `left`, `center`, `right`,
     *                                     `bottom-left`, `bottom` or `bottom-right` (the default)
     * @param int|float|null    $scale     the mark's width as a percentage of the photo's width, 1-100
     *                                     (25 by default)
     * @param int|float|null    $opacity   1-100 (50 by default)
     * @param int|float|null    $margin    distance from the edges as a percentage of the photo's shorter
     *                                     side, 0-50 (3 by default)
     * @param bool|null         $tile      repeat the mark across the whole photo; `position` is then unused
     */
    public function watermark(
        string|Input $image,
        ?string $text = null,
        string|Input|null $watermark = null,
        ?string $color = null,
        ?string $position = null,
        int|float|null $scale = null,
        int|float|null $opacity = null,
        int|float|null $margin = null,
        ?bool $tile = null,
        ?string $format = null,
        ?int $quality = null,
    ): ImageResult {
        $extra = $watermark !== null ? ['watermark' => Input::from($watermark)] : [];

        return new ImageResult($this->postImages('/v1/watermark', [$image], [
            'text' => $text,
            'color' => $color,
            'position' => $position,
            'scale' => $scale,
            'opacity' => $opacity,
            'margin' => $margin,
            'tile' => $tile,
            'format' => $format,
            'quality' => $quality,
        ], $extra));
    }

    /**
     * Expands the canvas: to a shape (`aspect`, the photo centred) or by
     * pixels on each side. The new area continues the photo by inpainting,
     * which suits plain and softly textured backgrounds. It does not invent
     * content: a subject cut off at the edge stays cut off. Each side can
     * grow by at most the photo's own width or height.
     *
     * @param string|int|float|null $aspect the shape to reach, as width:height (`1:1`) or a number;
     *                                      not with the sides
     * @param int|null              $left   pixels to add on the left
     * @param int|null              $right  pixels to add on the right
     * @param int|null              $top    pixels to add at the top
     * @param int|null              $bottom pixels to add at the bottom
     * @param string|null           $fill   `inpaint` (the default), or a colour (`white`, `black`, `#rrggbb`)
     */
    public function expand(
        string|Input $image,
        string|int|float|null $aspect = null,
        ?int $left = null,
        ?int $right = null,
        ?int $top = null,
        ?int $bottom = null,
        ?string $fill = null,
        ?string $format = null,
        ?int $quality = null,
    ): ImageResult {
        return new ImageResult($this->postImages('/v1/expand', [$image], [
            'aspect' => $aspect,
            'left' => $left,
            'right' => $right,
            'top' => $top,
            'bottom' => $bottom,
            'fill' => $fill,
            'format' => $format,
            'quality' => $quality,
        ]));
    }

    /**
     * Traces an image into SVG, for logos, icons and flat drawings: each
     * area of one colour becomes a filled path. A photograph traces into
     * thousands of paths and a large file.
     *
     * @param string|null $mode    `color` (the default) or `mono`: whatever is darker than mid grey,
     *                             as one black shape
     * @param string|null $curves  `spline` (smooth curves, the default), `polygon` (straight segments)
     *                             or `pixel` (every pixel step, for pixel art)
     * @param int|null    $detail  bits kept per colour channel when grouping pixels, 1-8 (8 by default);
     *                             lower merges similar shades into fewer shapes
     * @param int|null    $speckle areas smaller than this many pixels across are dropped, 0-100
     *                             (4 by default)
     */
    public function vectorize(
        string|Input $image,
        ?string $mode = null,
        ?string $curves = null,
        ?int $detail = null,
        ?int $speckle = null,
    ): VectorizeResult {
        return new VectorizeResult($this->postImages('/v1/vectorize', [$image], [
            'mode' => $mode,
            'curves' => $curves,
            'detail' => $detail,
            'speckle' => $speckle,
        ]));
    }

    /**
     * Finds every face and hides it inside an ellipse. Check the result's
     * `faces` before relying on it: a face that was not found still shows.
     *
     * @param string|null    $mode     `blur` (the default), `pixelate`, or `fill`: the only one that
     *                                 leaves nothing of the face
     * @param int|float|null $strength how strong the blur or how coarse the mosaic is, 1-100 (50 by default)
     * @param int|float|null $padding  how far past the face's box the hidden area reaches, as a fraction
     *                                 of the box, 0 to 1 (0.15 by default)
     * @param string|null    $color    the colour for `fill`: `black` (the default), `white`, `#rrggbb`
     * @param int|float|null $minScore how sure the model must be that something is a face, 0.1 to 1
     *                                 (0.6 by default); lower finds more faces and more things that are not
     */
    public function blurFaces(
        string|Input $image,
        ?string $mode = null,
        int|float|null $strength = null,
        int|float|null $padding = null,
        ?string $color = null,
        int|float|null $minScore = null,
        ?string $format = null,
        ?int $quality = null,
    ): BlurFacesResult {
        return new BlurFacesResult($this->postImages('/v1/blur-faces', [$image], [
            'mode' => $mode,
            'strength' => $strength,
            'padding' => $padding,
            'color' => $color,
            'min_score' => $minScore,
            'format' => $format,
            'quality' => $quality,
        ]));
    }

    /**
     * Frames a portrait for an identity document: levels the head, scales
     * it to the document's head size, and crops to the document's photo
     * size. Give a `document`, or the photo's and the head's sizes. A photo
     * with no face, or more than one, is refused (`unprocessable`).
     *
     * The photo's own background is kept unless `background` says
     * otherwise, because passport authorities do not accept edited photos.
     *
     * @param string|null    $document   a document's name ({@see Photos::idPhotos()}); not with the sizes
     * @param int|float|null $widthMm    the photo's width, 10-200; with `heightMm` and both head sizes
     *                                   in place of a document
     * @param int|float|null $heightMm   the photo's height, 10-200
     * @param int|float|null $headMinMm  the smallest allowed head, chin to crown
     * @param int|float|null $headMaxMm  the largest allowed head
     * @param int|float|null $eyesMinMm  the eyes' smallest distance from the bottom edge, for documents
     *                                   with such a rule; needs `eyesMaxMm`
     * @param int|float|null $eyesMaxMm  the eyes' largest distance from the bottom edge
     * @param int|null       $dpi        pixels per inch of the result, 100-1200 (300 by default); it is
     *                                   also written into the file
     * @param string|null    $background `keep` (the default: the photo's own), `document` (the colour the
     *                                   document asks for), or a colour (`white`, `#rrggbb`)
     * @param string|null    $format     output format; `jpg` by default
     */
    public function idPhoto(
        string|Input $image,
        ?string $document = null,
        int|float|null $widthMm = null,
        int|float|null $heightMm = null,
        int|float|null $headMinMm = null,
        int|float|null $headMaxMm = null,
        int|float|null $eyesMinMm = null,
        int|float|null $eyesMaxMm = null,
        ?int $dpi = null,
        ?string $background = null,
        ?string $format = null,
        ?int $quality = null,
    ): IdPhotoResult {
        return new IdPhotoResult($this->postImages('/v1/id-photo', [$image], [
            'document' => $document,
            'width_mm' => $widthMm,
            'height_mm' => $heightMm,
            'head_min_mm' => $headMinMm,
            'head_max_mm' => $headMaxMm,
            'eyes_min_mm' => $eyesMinMm,
            'eyes_max_mm' => $eyesMaxMm,
            'dpi' => $dpi,
            'background' => $background,
            'format' => $format,
            'quality' => $quality,
        ]));
    }

    /**
     * Lists the documents {@see Photos::idPhoto()} knows: each one's photo
     * size, head size, background and notes, with the official pages the
     * rules were read from.
     *
     * @return list<IdPhotoDocument>
     */
    public function idPhotos(): array
    {
        /** @var list<IdPhotoDocument> */
        return $this->list($this->get('/v1/id-photos'));
    }

    /**
     * Removes noise and JPEG artefacts. It takes photos up to 12 megapixels
     * and is slow without a GPU. On a photo that is already clean it removes
     * a little fine texture.
     *
     * @param int|float|null $strength how much of the cleaned result replaces the photo, 1-100
     *                                 (100 by default)
     */
    public function denoise(
        string|Input $image,
        int|float|null $strength = null,
        ?string $format = null,
        ?int $quality = null,
    ): ImageResult {
        return new ImageResult($this->postImages('/v1/denoise', [$image], [
            'strength' => $strength,
            'format' => $format,
            'quality' => $quality,
        ]));
    }

    /**
     * Colours a black-and-white photo. The colours are guesses. A photo that
     * already has colour is treated as black and white.
     *
     * @param int|float|null $strength how colourful the result is, 1-100 (100 by default); lower is paler
     */
    public function colorize(
        string|Input $image,
        int|float|null $strength = null,
        ?string $format = null,
        ?int $quality = null,
    ): ImageResult {
        return new ImageResult($this->postImages('/v1/colorize', [$image], [
            'strength' => $strength,
            'format' => $format,
            'quality' => $quality,
        ]));
    }

    /**
     * Cuts out the object at a point or in a box, or returns its mask (which
     * {@see Photos::erase()} takes to remove the object). Give points, a
     * box, or both. Coordinates are in the photo's pixels. When nothing is
     * found at the points the call is refused (`unprocessable`).
     *
     * @param list<array{int|float, int|float}|string> $points  points on the object, each `[x, y]` (or the
     *                                                          string `"x,y"`)
     * @param list<array{int|float, int|float}|string> $exclude points that must stay outside the selection,
     *                                                          in the same form
     * @param array{int|float, int|float, int|float, int|float}|string|null $box
     *                                                          a box around the object, `[x, y, width, height]`
     *                                                          (or the string `"x,y,width,height"`)
     * @param string|null                              $pick    which of the model's answers: `auto` (the surest,
     *                                                          the default), `smallest` (the part clicked) or
     *                                                          `largest` (the whole it belongs to)
     * @param bool|null                                $mask    return the greyscale mask instead of the cutout
     * @param bool|null                                $crop    crop to the object
     * @param int|null                                 $padding margin around the object when cropping, in pixels
     * @param string|null                              $background composite onto a colour (`white`, `#rrggbb`);
     *                                                          omit for transparency
     * @param string|null                              $format  output format; `png` by default
     */
    public function select(
        string|Input $image,
        array $points = [],
        array $exclude = [],
        array|string|null $box = null,
        ?string $pick = null,
        ?bool $mask = null,
        ?bool $crop = null,
        ?int $padding = null,
        ?string $background = null,
        ?string $format = null,
        ?int $quality = null,
    ): SelectResult {
        return new SelectResult($this->postImages('/v1/select', [$image], [
            'point' => array_map(self::numbers(...), $points),
            'exclude' => array_map(self::numbers(...), $exclude),
            'box' => $box !== null ? self::numbers($box) : null,
            'pick' => $pick,
            'mask' => $mask,
            'crop' => $crop,
            'padding' => $padding,
            'background' => $background,
            'format' => $format,
            'quality' => $quality,
        ]));
    }

    /**
     * Redraws each face sharp, for old, blurred, small or noisy photos. The
     * rest of the photo is not touched. The model invents the detail: do
     * not use it where the face must be exactly the person's own, such as
     * an identity document or evidence.
     *
     * @param int|float|null $strength how much of the restored face replaces the original, 1-100
     *                                 (100 by default)
     * @param int|float|null $minScore how sure the detector must be that something is a face, 0.1 to 1
     *                                 (0.6 by default)
     */
    public function restoreFaces(
        string|Input $image,
        int|float|null $strength = null,
        int|float|null $minScore = null,
        ?string $format = null,
        ?int $quality = null,
    ): RestoreFacesResult {
        return new RestoreFacesResult($this->postImages('/v1/restore-faces', [$image], [
            'strength' => $strength,
            'min_score' => $minScore,
            'format' => $format,
            'quality' => $quality,
        ]));
    }

    /**
     * Applies a built-in preset or a recipe. Give one of the two.
     *
     * @param string|null $preset a preset's name ({@see Photos::presets()})
     * @param string|null $recipe a recipe as TOML
     */
    public function run(string|Input $image, ?string $preset = null, ?string $recipe = null): ImageResult
    {
        $extra = $recipe !== null ? ['recipe' => Input::fromBytes($recipe, self::RECIPE_FILENAME)] : [];

        return new ImageResult($this->postImages('/v1/run', [$image], ['preset' => $preset], $extra));
    }

    // --- Check and fix ----------------------------------------------------

    /**
     * Fixes a photo for a marketplace, and checks the result.
     *
     * @param string $marketplace `amazon`, `ebay`, `etsy`, `shopify`, `google`, `allegro`, `zalando`
     */
    public function fix(string|Input $image, string $marketplace): FixResult
    {
        return new FixResult($this->postImages('/v1/fix', [$image], ['marketplace' => $marketplace]));
    }

    /**
     * Checks photo quality, and a marketplace's rules when one is named.
     *
     * @param string|null $marketplace check against this marketplace's rules
     * @param bool|null   $subject     also find the product, for framing and background checks
     * @param bool|null   $text        also read the text in the photo
     *
     * @return CheckResult
     */
    public function check(
        string|Input $image,
        ?string $marketplace = null,
        ?bool $subject = null,
        ?bool $text = null,
    ): array {
        /** @var CheckResult */
        return $this->object($this->postImages('/v1/check', [$image], [
            'marketplace' => $marketplace,
            'subject' => $subject,
            'text' => $text,
        ]));
    }

    /**
     * Lists the marketplaces and their image rules.
     *
     * @return list<Marketplace>
     */
    public function marketplaces(): array
    {
        /** @var list<Marketplace> */
        return $this->list($this->get('/v1/marketplaces'));
    }

    // --- Read -------------------------------------------------------------

    /**
     * Reads barcodes.
     *
     * @param bool|null   $allFormats also read EAN-8, UPC-E, Code 128/39/93, QR, Data Matrix, PDF417
     * @param string|null $engine     `auto` (the default), `zbar` or `rxing`
     *
     * @return BarcodesResult
     */
    public function readBarcodes(string|Input $image, ?bool $allFormats = null, ?string $engine = null): array
    {
        /** @var BarcodesResult */
        return $this->object($this->postImages('/v1/barcodes', [$image], [
            'all_formats' => $allFormats,
            'engine' => $engine,
        ]));
    }

    /**
     * Reads text (OCR).
     *
     * @return TextResult
     */
    public function readText(string|Input $image): array
    {
        /** @var TextResult */
        return $this->object($this->postImages('/v1/ocr', [$image]));
    }

    /**
     * Reads product details from one to eight photos of one product's label.
     *
     * @param string|Input|list<string|Input> $images
     *
     * @return LabelResult
     */
    public function readLabel(string|Input|array $images): array
    {
        /** @var LabelResult */
        return $this->object($this->postImages('/v1/label', self::upToEight($images)));
    }

    /**
     * Matches one to eight photos of one product against one of your
     * organisation's catalogues (uploaded on the console's Catalogues page).
     *
     * Catalogue matching is turned on per organisation: until it is on for
     * yours and a catalogue is uploaded, the API answers 404
     * (NotFoundException). A candidate's evidence
     * is what agreed beyond the name: barcode, barcode_text, sku, brand, and
     * by the catalogue's kind quantity, pack, spf (food, cosmetics),
     * strength, count (pharmacy, household), spec, model (electronics),
     * cask_number, age, age_weak, vintage, abv, size (wine and spirits).
     * The label holds the values read, each with its unit where it has one.
     *
     * @param string|Input|list<string|Input> $images
     * @param string|null                     $catalogue catalogue name; optional when you have exactly one
     * @param int|null                        $top       candidates to return, 1-50 (5 by default)
     *
     * @return IdentifyResult
     */
    public function identify(string|Input|array $images, ?string $catalogue = null, ?int $top = null): array
    {
        /** @var IdentifyResult */
        return $this->object($this->postImages('/v1/identify', self::upToEight($images), [
            'catalogue' => $catalogue,
            'top' => $top,
        ]));
    }

    /**
     * Lists the main colours of an image, the most common first.
     * Transparent pixels do not count, so a cutout's palette is the subject's.
     *
     * @param int|null $colors how many colours at most, 1-16 (5 by default)
     *
     * @return PaletteResult
     */
    public function palette(string|Input $image, ?int $colors = null): array
    {
        /** @var PaletteResult */
        return $this->object($this->postImages('/v1/palette', [$image], ['colors' => $colors]));
    }

    /**
     * Finds faces: a box and five landmarks each, the surest first. It finds
     * faces; it does not recognise who anyone is. Coordinates are in the
     * photo's pixels. "Right" and "left" are the person's own, so
     * `right_eye` is the one on the left of the photo.
     *
     * @param int|float|null $minScore how sure the model must be that something is a face, 0.1 to 1
     *                                 (0.6 by default)
     *
     * @return FacesResult
     */
    public function findFaces(string|Input $image, int|float|null $minScore = null): array
    {
        /** @var FacesResult */
        return $this->object($this->postImages('/v1/faces', [$image], ['min_score' => $minScore]));
    }

    /**
     * Describes the photo in words, in English only. The description can be
     * wrong (a colour, a count, what an object is): read it before
     * publishing it.
     *
     * @param string|null $detail how much to say: `short` (one sentence, the default), `detailed`
     *                            (two or three) or `paragraph`
     *
     * @return CaptionResult
     */
    public function caption(string|Input $image, ?string $detail = null): array
    {
        /** @var CaptionResult */
        return $this->object($this->postImages('/v1/caption', [$image], ['detail' => $detail]));
    }

    // --- Account ----------------------------------------------------------

    /**
     * The caller's account: credits left and prices, or `unlimited`.
     *
     * @return Account
     */
    public function account(): array
    {
        /** @var Account */
        return $this->object($this->get('/v1/account'));
    }

    /**
     * Lists the built-in presets for {@see Photos::run()}.
     *
     * @return list<Preset>
     */
    public function presets(): array
    {
        /** @var list<Preset> */
        return $this->list($this->get('/v1/presets'));
    }

    /**
     * Whether the service is ready: `ok`, `loading` or `failed`. The last
     * two are not errors here.
     */
    public function health(): string
    {
        $response = $this->send('GET', '/health', retry: false);
        if ($response->status === 200 || $response->status === 503) {
            $decoded = json_decode($response->body, true);
            if (is_array($decoded) && is_string($decoded['status'] ?? null)) {
                return $decoded['status'];
            }
        }

        throw ApiException::fromResponse($response);
    }

    // --- Batch jobs -------------------------------------------------------

    /**
     * Starts a background job: one operation applied to many images.
     *
     * @param string                                  $operation  `run`, `remove-background`, `resize`, `upscale`,
     *                                                            `ocr`, `barcodes`, `check`, `fix`, `remove-text`,
     *                                                            `label`, `enhance`, `crop`, `palette`, `watermark`,
     *                                                            `expand`, `vectorize`, `faces`, `blur-faces`,
     *                                                            `id-photo`, `denoise`, `colorize`, `select`,
     *                                                            `restore-faces` or `caption`
     * @param list<string|Input>                      $inputs     images: a string starting with `http://` or
     *                                                            `https://` is a URL the service fetches; any other
     *                                                            string is a file path, and an {@see Input} is
     *                                                            uploaded
     * @param array<string, scalar|list<scalar>|null> $params     the operation's options under their API names,
     *                                                            e.g. `['format' => 'png', 'max_bytes' => 200000]`.
     *                                                            An option that repeats is a list, e.g. for
     *                                                            `select`: `['point' => ['160,130', '170,200']]`.
     *                                                            A job item is one image: `watermark` takes `text`
     *                                                            only, and `remove-background` no background image
     * @param string|null                             $recipe     recipe TOML, for operation `run`
     * @param string|null                             $webhookUrl called with a signed `job.completed` event
     *                                                            ({@see Webhook::verify()})
     *
     * @return Job
     */
    public function createJob(
        string $operation,
        array $inputs,
        array $params = [],
        ?string $recipe = null,
        ?string $webhookUrl = null,
    ): array {
        if ($inputs === []) {
            throw new InvalidInputException('A job needs at least one image or URL.');
        }
        // A null or an empty list is an option left unset.
        $params = array_filter($params, static fn(mixed $value): bool => $value !== null && $value !== []);

        $uploads = false;
        foreach ($inputs as $input) {
            $uploads = $uploads || !self::isUrl($input);
        }

        if (!$uploads) {
            $payload = ['operation' => $operation, 'inputs' => array_values($inputs)];
            if ($params !== []) {
                // A JSON object cannot repeat a key: when an option is a list,
                // the params go as a query string, which the API takes too.
                $repeats = array_filter($params, is_array(...)) !== [];
                $payload['params'] = $repeats ? self::query($params) : $params;
            }
            if ($recipe !== null) {
                $payload['recipe'] = $recipe;
            }
            if ($webhookUrl !== null) {
                $payload['webhook_url'] = $webhookUrl;
            }
            $body = json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            $contentType = 'application/json';
        } else {
            $form = new Multipart();
            $form->field('operation', $operation);
            if ($params !== []) {
                $form->field('params', self::query($params));
            }
            if ($recipe !== null) {
                $form->file('recipe', self::RECIPE_FILENAME, $recipe);
            }
            if ($webhookUrl !== null) {
                $form->field('webhook_url', $webhookUrl);
            }
            foreach ($inputs as $input) {
                if (self::isUrl($input)) {
                    $form->field('url', $input);
                } else {
                    $input = Input::from($input);
                    $form->file('image', $input->filename, $input->contents());
                }
            }
            $body = $form->body();
            $contentType = $form->contentType();
        }

        // A job must not be created twice: retried on 503 only.
        $response = $this->send('POST', '/v1/jobs', body: $body, contentType: $contentType, retryConnection: false);

        /** @var Job */
        return $this->object($response);
    }

    /**
     * A job's status, progress and per-item results.
     *
     * @return Job
     */
    public function getJob(string $id): array
    {
        /** @var Job */
        return $this->object($this->get('/v1/jobs/' . rawurlencode($id)));
    }

    /**
     * Your recent jobs, newest first (up to 100).
     *
     * @return list<JobSummary>
     */
    public function listJobs(): array
    {
        /** @var list<JobSummary> */
        return $this->list($this->get('/v1/jobs'));
    }

    /**
     * Cancels a job (if unfinished) and deletes it and its results.
     */
    public function deleteJob(string $id): void
    {
        $this->ok($this->send('DELETE', '/v1/jobs/' . rawurlencode($id)));
    }

    /**
     * The result of one finished item of a job.
     *
     * @param int $item the item's index in the job's `items`, from 0
     */
    public function jobItemResult(string $id, int $item): JobItemResult
    {
        return new JobItemResult($this->get('/v1/jobs/' . rawurlencode($id) . '/items/' . $item));
    }

    /**
     * Polls a job until it has completed, and returns it. `completed` means
     * every item was attempted: see `counts` for failures.
     *
     * @param float $pollInterval seconds between polls
     * @param float $timeout      seconds to wait overall
     *
     * @return Job
     *
     * @throws WaitTimeoutException when the job has not completed in time
     */
    public function waitForJob(string $id, float $pollInterval = 2.0, float $timeout = 600.0): array
    {
        $deadline = microtime(true) + $timeout;
        while (true) {
            $job = $this->getJob($id);
            if ($job['status'] === 'completed') {
                return $job;
            }
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                throw new WaitTimeoutException(
                    sprintf('Job %s did not complete within %s seconds (status: %s).', $id, $timeout, $job['status']),
                    $job,
                );
            }
            ($this->sleep)(min($pollInterval, $left));
        }
    }

    /**
     * The key your webhooks are signed with ({@see Webhook::verify()}).
     *
     * @return WebhookSecret
     */
    public function webhookSecret(): array
    {
        /** @var WebhookSecret */
        return $this->object($this->get('/v1/webhook-secret'));
    }

    // --- Internals --------------------------------------------------------

    /**
     * Posts images as `multipart/form-data` and returns the 2xx response.
     *
     * @param list<string|Input>                         $images sent as `image` parts
     * @param array<string, scalar|list<string>|null>    $query  options; null ones are left out
     * @param array<string, Input>                       $extra  further file parts, by name
     */
    private function postImages(string $path, array $images, array $query = [], array $extra = []): Response
    {
        $form = new Multipart();
        foreach ($images as $image) {
            $image = Input::from($image);
            $form->file('image', $image->filename, $image->contents());
        }
        foreach ($extra as $name => $part) {
            $form->file($name, $part->filename, $part->contents());
        }

        return $this->ok($this->send('POST', $path, $query, $form->body(), $form->contentType()));
    }

    private function get(string $path): Response
    {
        return $this->ok($this->send('GET', $path));
    }

    /**
     * Sends a request, retrying a busy `503` and (unless told not to) a failed
     * connection. Returns whatever response comes last, error statuses too.
     *
     * @param array<string, scalar|list<string>|null> $query
     * @param bool                                    $retry           false: one attempt only
     * @param bool                                    $retryConnection false: retry a 503, not a failed connection
     */
    private function send(
        string $method,
        string $path,
        array $query = [],
        ?string $body = null,
        ?string $contentType = null,
        bool $retry = true,
        bool $retryConnection = true,
    ): Response {
        $url = $this->baseUrl . $path;
        $queryString = self::query($query);
        if ($queryString !== '') {
            $url .= '?' . $queryString;
        }
        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'User-Agent' => 'photos-sh-php/' . self::VERSION,
            'Accept' => '*/*',
        ];
        if ($contentType !== null) {
            $headers['Content-Type'] = $contentType;
        }
        $request = new Request($method, $url, $headers, $body, $this->timeout);
        $retries = $retry ? max(0, $this->maxRetries) : 0;

        for ($attempt = 0; ; ++$attempt) {
            // Waits 1 s, then 2 s (then 4 s, ...) unless the server says how long.
            $delay = (float) (2 ** $attempt);
            try {
                $response = $this->transport->send($request);
            } catch (TimeoutException $e) {
                throw $e;
            } catch (ConnectionException $e) {
                if (!$retryConnection || $attempt >= $retries) {
                    throw $e;
                }
                ($this->sleep)($delay);

                continue;
            }
            if ($response->status !== 503 || $attempt >= $retries || !self::isBusy($response)) {
                return $response;
            }
            $retryAfter = trim($response->header('Retry-After') ?? '');
            if (ctype_digit($retryAfter)) {
                $delay = (float) min((int) $retryAfter, self::MAX_RETRY_AFTER);
            }
            ($this->sleep)($delay);
        }
    }

    /**
     * Whether a 503 is worth retrying: the API's `busy`, or a body that is
     * not the API's JSON error (a proxy's). `model_unavailable` is permanent.
     */
    private static function isBusy(Response $response): bool
    {
        $decoded = json_decode($response->body, true);
        if (!is_array($decoded) || !is_array($decoded['error'] ?? null) || !is_string($decoded['error']['code'] ?? null)) {
            return true;
        }

        return $decoded['error']['code'] === ApiException::BUSY;
    }

    /**
     * @throws ApiException unless the status is 2xx
     */
    private function ok(Response $response): Response
    {
        if ($response->status < 200 || $response->status >= 300) {
            throw ApiException::fromResponse($response);
        }

        return $response;
    }

    /**
     * Decodes a JSON object.
     *
     * @return array<string, mixed>
     */
    private function object(Response $response): array
    {
        $decoded = json_decode($response->body, true);
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new PhotosException(sprintf(
                'Expected a JSON object from the API, got: %s',
                substr($response->body, 0, 200),
            ));
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Decodes a JSON array.
     *
     * @return list<mixed>
     */
    private function list(Response $response): array
    {
        $decoded = json_decode($response->body, true);
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new PhotosException(sprintf(
                'Expected a JSON array from the API, got: %s',
                substr($response->body, 0, 200),
            ));
        }

        return $decoded;
    }

    /**
     * Builds a query string: nulls left out, booleans as `true`/`false`,
     * lists as a repeated key.
     *
     * @param array<string, scalar|list<scalar>|null> $options
     */
    private static function query(array $options): string
    {
        $pairs = [];
        foreach ($options as $name => $value) {
            if ($value === null) {
                continue;
            }
            foreach (is_array($value) ? $value : [$value] as $item) {
                $text = is_bool($item) ? ($item ? 'true' : 'false') : (string) $item;
                $pairs[] = rawurlencode($name) . '=' . rawurlencode($text);
            }
        }

        return implode('&', $pairs);
    }

    /**
     * Numbers as the API takes them in the query, `[10, 20]` as `10,20`.
     * A string is passed through.
     *
     * @param array<int|float>|string $numbers
     */
    private static function numbers(array|string $numbers): string
    {
        return is_array($numbers) ? implode(',', $numbers) : $numbers;
    }

    /**
     * @param string|Input|array<mixed> $images
     *
     * @return list<string|Input>
     */
    private static function upToEight(string|Input|array $images): array
    {
        $list = [];
        foreach (is_array($images) ? $images : [$images] as $image) {
            if (!is_string($image) && !$image instanceof Input) {
                throw new InvalidInputException('Each image must be a file path or an Input.');
            }
            $list[] = $image;
        }
        if ($list === [] || count($list) > 8) {
            throw new InvalidInputException('Send one to eight images.');
        }

        return $list;
    }

    /**
     * @phpstan-assert-if-true string $input
     */
    private static function isUrl(string|Input $input): bool
    {
        return is_string($input) && preg_match('#^https?://#i', $input) === 1;
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);
        if (!is_string($value) || $value === '') {
            $value = $_ENV[$name] ?? null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
