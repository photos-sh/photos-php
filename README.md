# Photos.sh for PHP

The PHP client for the [Photos.sh](https://photos.sh) API: image tools for
product photos. Background removal and staging, resizing and compression, AI
upscaling, object and text removal, light and colour fixes, cropping,
watermarks, SVG tracing, clean-up and colourising, face tools and identity
photos, quality and marketplace checks with auto-fix, barcode, text and label
reading, captions and palettes, presets, and batch jobs with signed webhooks.

- PHP 8.3 or later with the `json` extension. The `curl` extension is needed only for the default transport, not when you pass your own. No other dependencies.
- The HTTP layer is a one-method interface, so it can run on `wp_remote_request`,
  Guzzle or anything else.

## Install

```bash
composer require photos-sh/photos
```

## Quick start

```php
use PhotosSh\Photos;

$photos = new Photos('your-api-key'); // or set PHOTOS_API_KEY

$cutout = $photos->removeBackground('shoe.jpg');
$cutout->save('shoe.png');

echo $cutout->width, 'x', $cutout->height, ' ', $cutout->contentType, "\n";
```

Create an API key on your organisation's API keys page in the Photos.sh console.

## Images in

Wherever a method takes an image, pass a file path, or an `Input` made from
bytes, a stream or a path. A plain string always means a path.

```php
use PhotosSh\Input;

$photos->resize('photos/shoe.jpg', width: 800);
$photos->resize(Input::fromPath('photos/shoe.jpg'), width: 800);
$photos->resize(Input::fromBytes($bytes, 'shoe.jpg'), width: 800);
$photos->resize(Input::fromStream(fopen('php://stdin', 'rb')), width: 800);
```

Accepted formats: JPEG, PNG, WebP, GIF, TIFF, BMP, and HEIC/HEIF and AVIF where
the server supports them.

## Images out

Methods that return an image give an `ImageResult`:

```php
$result = $photos->resize('shoe.jpg', width: 800, format: 'webp');

$result->bytes;        // the encoded image
$result->contentType;  // "image/webp"
$result->width;        // 800
$result->height;       // 600
$result->quality;      // quality used for a lossy format, or null
$result->serverTiming; // the raw Server-Timing header, or null
$result->extension();  // "webp"
$result->save('shoe.webp');
```

Where the API says more about the image, the method returns a subclass of
`ImageResult` with those values: `removeBackground`, `removeText`, `fix`,
`crop`, `vectorize`, `blurFaces`, `idPhoto`, `select` and `restoreFaces`.

## Options

Options are named arguments. One you leave out is not sent, and the API's
default applies.

## Edit

### Remove the background

```php
$result = $photos->removeBackground(
    'shoe.jpg',
    format: 'png',          // png (default), jpg, webp, avif
    quality: 90,            // 1-100, lossy formats
    background: '#ffffff',  // composite onto a colour; omit for transparency
    crop: true,             // crop to the subject
    padding: 20,            // margin around the subject when cropping, px
    mask: false,            // true: return the greyscale alpha mask
    studio: false,          // true: a studio shot; also keep its box or case
    model: 'atlas',         // the model that finds the subject: atlas (default), lumen, muse
);
```

The same call can stage the cutout: put the subject on a canvas, add a shadow
or a reflection, and choose what is behind it.

```php
$result = $photos->removeBackground(
    'shoe.jpg',
    width: 1200,            // the canvas; needs height
    height: 1200,
    fill: 0.85,             // how much of the canvas the subject may take, 0.05-1
    align: 'bottom',        // center (default), top, bottom
    shadow: 'contact',      // none (default), contact, drop
    reflection: false,      // true: a fading mirror image under the subject
    background: '#f4f4f4',  // or blur: 40, or backgroundImage: 'studio.jpg'
);

if ($result->subjectFound === false) {
    echo "No subject in the photo: it came back unchanged.\n";
}
```

Behind the subject goes at most one of: a colour (`background`), an image
(`backgroundImage`, a path or an `Input`, scaled to cover the result), or the
photo's own background, blurred (`blur`, 1 to 100). `crop` does not go with a
canvas or with `blur`, and `mask` takes none of these options.

`subjectFound` is `false` when no subject is found (one covering under 3% of
the photo does not count): the photo then comes back unchanged, or with `mask`
the mask. In every other case it is `null`: the API does not say.

For studio shots (a product on a plain, light backdrop, as in a light tent),
`studio: true` also keeps what clearly stands out from the backdrop and
touches the product, such as the gift box, case or lid shown with a bottle, or
a pale box the model left half transparent. Shadows and the floor below the
product are left out. A photo without a plain light backdrop is cut out as
without it.

`model` picks the model that finds the subject. Each costs the same; try
another when one drops part of what you sell. Left out, the API uses `atlas`.

| Model | Recommended for |
|---|---|
| `atlas` (default) | Everyday product photos: a product alone or with its box, on any backdrop. |
| `lumen` | Pale packaging on a light backdrop, such as a cream or wooden box beside a bottle, and close-ups where the product fills the photo. On those, `atlas` can keep the bottle and drop the box. It may keep a dark backdrop at the photo's edge, and can cut away part of an open frame or wire stand. |
| `muse` | People: portraits, and models wearing the product. |

```php
$boxed = $photos->removeBackground('perfume.jpg', studio: true, model: 'lumen');
$portrait = $photos->removeBackground('model.jpg', model: 'muse');
```

`model` is a string, so a model the API adds later works without a new
version of this library. It works with `studio`.

### Resize, convert and compress

```php
$result = $photos->resize(
    'shoe.jpg',
    width: 1200,
    height: 1200,
    fit: 'contain',      // contain (default), cover, fill
    enlarge: false,      // allow scaling up with contain
    format: 'jpg',
    quality: 85,
    maxBytes: 300_000,   // lower the quality until the file fits
    background: 'white', // flatten transparency onto this colour
    trim: true,          // cut away an even border first
    trimTolerance: 10,   // how far a pixel may differ from the border's colour, 0-255
    dpi: 300,            // print resolution to write into a JPEG or PNG
);
```

### Upscale

```php
$result = $photos->upscale('small.jpg', scale: 2, format: 'jpg', quality: 90); // scale 1-4, default 4
```

### Erase objects

Give the area as rectangles (`[x, y, width, height]`), as a mask image the size
of the photo (white = remove), or both.

```php
$result = $photos->erase('room.jpg', rects: [[40, 60, 200, 120], [500, 80, 64, 64]]);
$result = $photos->erase('room.jpg', mask: 'room-mask.png', format: 'jpg', quality: 90);
```

### Remove text, watermarks and captions

```php
$result = $photos->removeText(
    'listing.jpg',
    region: 'background',    // background (default: text off the product) or all
    matching: 'sale|€|www\.', // only lines matching this regular expression
    format: 'jpg',
    quality: 90,
);

echo $result->removed, ' removed, ', $result->kept, " kept\n";
$result->save('clean.jpg');
```

### Fix light and colour

```php
$result = $photos->enhance('shoe.jpg');  // automatic: levels and white balance

$result = $photos->enhance(
    'shoe.jpg',
    auto: true,       // keep the automatic part beside the settings below
    brightness: 10,   // -100 to 100; so are contrast, saturation and temperature
    contrast: 5,
    saturation: -20,  // -100 is black and white
    temperature: 15,  // below zero is cooler, above is warmer
    sharpen: 30,      // 0 to 100
    format: 'jpg',
    quality: 90,
);
```

With any setting, only what you give is applied. `levels: true` and
`whiteBalance: true` turn on one half of the automatic part.

### Crop to a shape

Finds the subject and crops the photo around it, keeping the background.

```php
$result = $photos->crop('shoe.jpg', aspect: '4:5');  // the largest 4:5 crop, moved to hold the subject

$result = $photos->crop(
    'shoe.jpg',
    aspect: '1:1',   // width:height, or a number such as 1.5
    zoom: true,      // the smallest crop that holds the subject and its padding
    padding: 0.1,    // room around the subject, as a fraction of its longest side
    width: 800,      // scale the crop to this size
    height: 800,
);

// A portrait or an avatar: crop around the faces.
$avatar = $photos->crop('team.jpg', aspect: '1:1', focus: 'face', zoom: true, padding: 0.6);

if ($result->subjectFound === false) {
    echo "No subject found: the crop is centred.\n";
}
```

### Add a logo or text

Give `text` or a `watermark` image (a path or an `Input`), not both.

```php
$result = $photos->watermark('shoe.jpg', text: '© Example Shop', color: 'white', position: 'bottom-right');

$result = $photos->watermark(
    'shoe.jpg',
    watermark: 'logo.png',  // a PNG with transparency works best
    position: 'center',     // top-left, top, top-right, left, center, right, bottom-left, bottom, bottom-right (default)
    scale: 25,              // the mark's width, % of the photo's width
    opacity: 50,            // 1-100
    margin: 3,              // distance from the edges, % of the photo's shorter side
    tile: false,            // true: repeat the mark across the whole photo
);
```

Text is drawn in a bold sans-serif with Latin, Greek and Cyrillic letters.

### Expand the canvas

```php
$result = $photos->expand('shoe.jpg', aspect: '16:9');          // to a shape, the photo centred
$result = $photos->expand('shoe.jpg', left: 200, right: 200);   // by pixels on each side; also top, bottom
$result = $photos->expand('shoe.jpg', top: 100, fill: 'white'); // a flat colour in place of inpainting
```

The new area continues the photo by inpainting, which suits plain and softly
textured backgrounds. It does not invent content: a subject cut off at the edge
stays cut off. Each side can grow by at most the photo's own width or height.

### Trace into SVG

For logos, icons and flat drawings. A photograph traces into thousands of paths
and a large file.

```php
$svg = $photos->vectorize(
    'logo.png',
    mode: 'color',     // color (default), mono
    curves: 'spline',  // spline (default), polygon, pixel
    detail: 8,         // 1-8: lower merges similar shades into fewer shapes
    speckle: 4,        // drop areas smaller than this many pixels across
);

$svg->save('logo.svg');         // $svg->bytes is the SVG's text
echo $svg->paths, " paths\n";
```

### Hide faces

```php
$result = $photos->blurFaces(
    'street.jpg',
    mode: 'blur',    // blur (default), pixelate, fill
    strength: 50,    // how strong the blur or how coarse the mosaic, 1-100
    padding: 0.15,   // how far past the face's box the hidden area reaches
    minScore: 0.6,   // lower finds more faces, and more things that are not
);
$result = $photos->blurFaces('street.jpg', mode: 'fill', color: 'black');

echo $result->faces, " faces hidden\n";
```

Check `faces` before relying on the result: a face that was not found still
shows. Only `fill` leaves nothing of a face.

### Identity photos

Frames a portrait for an identity document: levels the head, scales it to the
document's head size, and crops to the document's photo size. Give a document
from `idPhotos()`, or the sizes yourself.

```php
foreach ($photos->idPhotos() as $document) {
    echo $document['name'], ': ', $document['width_mm'], ' x ', $document['height_mm'], " mm\n";
}

$result = $photos->idPhoto('portrait.jpg', document: 'us-passport');

$result = $photos->idPhoto(
    'portrait.jpg',
    widthMm: 35,          // the sizes yourself, in place of a document
    heightMm: 45,
    headMinMm: 32,        // chin to crown
    headMaxMm: 36,
    dpi: 300,
    background: 'white',  // keep (default), document, or a colour
);

echo $result->passed ? 'nothing found' : implode(', ', $result->findings), "\n";
echo $result->headMm, " mm from chin to crown\n";
```

`idPhoto` keeps the photo's own background by default, because passport
authorities do not accept edited photos. Each document's `alteration` quotes
what its authority says.

`passed` means the tool found none of the faults it can see (`head_tilted`,
`not_facing_camera`, `head_cut_off`, `not_enough_room`, `low_resolution`,
`background_not_plain`). It is not an approval of the photo. A photo with no
face, or more than one, is refused (`ApiException::UNPROCESSABLE`).

### Remove noise and JPEG artefacts

```php
$result = $photos->denoise('grainy.jpg', strength: 100);  // 1-100: lower blends back towards the original
```

`denoise` is slow without a GPU and takes photos up to 12 megapixels.

### Colour a black-and-white photo

```php
$result = $photos->colorize('old.jpg', strength: 100);  // 1-100: lower is paler
```

`colorize` guesses the colours: it cannot know what colour a dress or a car was.

### Select an object

Cuts out the object at a point or in a box. Coordinates are in the photo's
pixels.

```php
$result = $photos->select('desk.jpg', points: [[420, 310]]);

$result = $photos->select(
    'desk.jpg',
    points: [[420, 310], [455, 290]],  // points on the object, each [x, y]
    exclude: [[300, 500]],             // points that must stay outside it
    box: [350, 200, 220, 260],         // a box around it: x, y, width, height
    pick: 'largest',                   // auto (default), smallest, largest
    crop: true,                        // crop to the object
    padding: 10,
    background: 'white',               // omit for transparency
);

echo $result->score, "\n";  // how well the model thinks the mask fits, 0 to 1
```

With `mask: true` the result is the object's mask, which `erase` takes to
remove the object:

```php
use PhotosSh\Input;

$mask = $photos->select('desk.jpg', points: [[420, 310]], mask: true);
$clean = $photos->erase('desk.jpg', mask: Input::fromBytes($mask->bytes, 'mask.png'));
```

When nothing is found at the points, the call is refused
(`ApiException::UNPROCESSABLE`).

### Restore faces

For old, blurred, small or noisy photos. Only the faces change.

```php
$result = $photos->restoreFaces('old-family.jpg', strength: 100, minScore: 0.6);

echo $result->faces, ' restored, ', $result->facesSkipped, " left alone\n";
```

`restoreFaces` invents the detail it draws, so do not use it where the face has
to be exactly the person's own, such as an identity document or evidence.

### Presets and recipes

```php
foreach ($photos->presets() as $preset) {
    echo $preset['name'], ': ', $preset['description'], "\n";
}

$result = $photos->run('shoe.jpg', preset: 'marketplace');
$result = $photos->run('shoe.jpg', recipe: file_get_contents('my-recipe.toml'));

echo $photos->preset('marketplace');  // a preset's recipe, as TOML, to start your own from
```

`preset()` throws `NotFoundException` for a name that is not a preset.

A recipe can also read the photo. The steps `[barcodes]` (with
`all_formats = true` for QR, Code 128 and the rest) and `[text]` leave the
image as it is, and what they read comes with the result:

```php
$result = $photos->run('jar.jpg', recipe: <<<'TOML'
    [barcodes]
    all_formats = true

    [text]

    [output]
    max_side = 1600
    TOML);

foreach ($result->barcodes ?? [] as $barcode) {
    echo $barcode['format'], ' ', $barcode['value'], ' ', $barcode['gs1'] ?? '-', "\n";
}
echo implode("\n", $result->text ?? []), "\n";  // the lines, top to bottom
if ($result->textTruncated) {
    echo "(more lines than fit in the header)\n";
}
```

`barcodes` and `text` are null when the recipe has no such step, and empty
when the step found nothing. A `run` job item's `image()` carries them too.
Reading steps need the engine release that has them (Vallic/photos#7); an
older engine refuses the recipe with a `422` `ApiException`.

## Check and fix

### Check a photo

```php
$check = $photos->check('shoe.jpg');                         // quality only: fast
$check = $photos->check('shoe.jpg', marketplace: 'amazon');  // plus Amazon's rules
$check = $photos->check('shoe.jpg', subject: true, text: true);

echo $check['metrics']['sharpness'] ?? '?', "\n";
foreach ($check['findings'] as $finding) {
    echo $finding['severity'], ' ', $finding['code'], ': ', $finding['message'], "\n";
}
if (isset($check['marketplace']) && !$check['marketplace']['passed']) {
    echo "Would be rejected.\n";
}
```

### Fix a photo for a marketplace

```php
$fixed = $photos->fix('shoe.jpg', marketplace: 'amazon');
$fixed->save('shoe-amazon.' . $fixed->extension());

if (!$fixed->passed) {
    echo 'Still failing: ', implode(', ', $fixed->findings), "\n";
}
```

### Marketplace rules

```php
foreach ($photos->marketplaces() as $marketplace) {
    echo $marketplace['name'], ' (', $marketplace['title'], '), checked ', $marketplace['checked'], "\n";
}
```

## Read

### Barcodes

```php
$scan = $photos->readBarcodes('box.jpg');                    // EAN-13, UPC-A, ITF-14
$scan = $photos->readBarcodes('box.jpg', allFormats: true, engine: 'auto');  // plus QR, Code 128, ...

foreach ($scan['barcodes'] as $barcode) {
    echo $barcode['format'], ' ', $barcode['value'], "\n";
}
```

### Text

```php
$text = $photos->readText('label.jpg');

foreach ($text['lines'] as $line) {
    echo $line['text'], ' (', round($line['score'] * 100), "%)\n";
}
```

### Label details

One to eight photos of one product:

```php
$reading = $photos->readLabel(['front.jpg', 'back.jpg']);

echo $reading['label']['gtin'] ?? 'no GTIN', "\n";
print_r($reading['label']);   // net quantity, ingredients, allergens, dates, ...
```

### Match a product in your catalogue

One to eight photos of one product, matched against one of your
organisation's catalogues. A catalogue is a spreadsheet of your products
(`product_id`, `title`, and where you have them `brand`, `barcode`, `sku`),
uploaded on the console's Catalogues page. Catalogue matching is turned on
per organisation: until it is on for yours and a catalogue is uploaded, the
API answers `404 not_found`. The catalogue's name may be left out when you
have exactly one.

Each catalogue is one kind of product, chosen when it is uploaded: any
product, food and drink, cosmetics, pharmacy and supplements, household and
pet, electronics, or wine and spirits. Each kind also compares its own values
with the label, so the right size or strength of a product sold in several is
found.

Each candidate's `evidence` says what agreed beyond the name: `barcode`,
`barcode_text`, `sku`, `brand`, and by the catalogue's kind `quantity`,
`pack`, `spf`, `strength`, `count`, `spec`, `model`, `cask_number`, `age`,
`age_weak`, `vintage`, `abv`, `size`. `label` holds the values read off the
photos.

```php
$match = $photos->identify(['front.jpg', 'back.jpg'], catalogue: 'shoes', top: 3);

foreach ($match['candidates'] as $candidate) {
    echo $candidate['title'], ' ', $candidate['score'], ' [', implode(', ', $candidate['evidence']), "]\n";
}
```

### Colours

The main colours of an image, the most common first. Transparent pixels do not
count, so a cutout's palette is the subject's.

```php
$palette = $photos->palette('shoe.jpg', colors: 5);  // 1-16 colours, 5 by default

foreach ($palette['colors'] as $color) {
    echo $color['hex'], ' ', round($color['share'] * 100), "%\n";  // also: rgb, as [r, g, b]
}
```

### Faces

Where the faces are. It finds faces; it does not recognise who anyone is.

```php
$found = $photos->findFaces('team.jpg', minScore: 0.6);

foreach ($found['faces'] as $face) {
    $box = $face['box'];  // x, y, width, height, in the photo's pixels
    echo round($box['width']), 'x', round($box['height']), ' at ', round($box['x']), ',', round($box['y']), "\n";

    [$x, $y] = $face['landmarks']['nose'];  // also: right_eye, left_eye, mouth_right, mouth_left
    echo $face['score'], ' ', $face['roll'], "\n";  // how sure the model is; the head's tilt in degrees
}
```

"Right" and "left" are the person's own, so `right_eye` is the one on the left
of the photo.

### Caption

```php
$caption = $photos->caption('shoe.jpg', detail: 'short');  // short (default), detailed, paragraph

echo $caption['caption'], "\n";
```

`caption` can be wrong and writes English only, so read the text before you
publish it.

## Account

```php
$account = $photos->account();
echo $account['credits'] ?? 'unlimited', "\n";  // also: account, key, prices

echo $photos->health(), "\n";  // "ok", "loading" or "failed"
```

## Batch jobs

A job applies one operation to many images in the background. Inputs are URLs
the service fetches (strings starting with `http://` or `https://`), files to
upload (any other string is a path), or `Input`s. `params` are the operation's
options under their API names.

```php
$job = $photos->createJob(
    'remove-background',
    ['https://example.com/photos/1.jpg', 'https://example.com/photos/2.jpg'],
    params: ['format' => 'png', 'crop' => true],
    webhookUrl: 'https://example.com/hooks/photos',  // optional
);

$job = $photos->waitForJob($job['id'], pollInterval: 2.0, timeout: 600.0);

echo $job['counts']['done'], ' done, ', $job['counts']['failed'], " failed\n";

foreach ($job['items'] as $n => $item) {
    if ($item['status'] === 'done') {
        $photos->jobItemResult($job['id'], $n)->save("result-$n.png");
    } else {
        echo $item['input'], ': ', $item['error']['message'] ?? 'failed', "\n";
    }
}

$photos->deleteJob($job['id']);
```

Uploads, and a recipe:

```php
use PhotosSh\Input;

$job = $photos->createJob('resize', ['a.jpg', 'b.jpg', Input::fromBytes($bytes, 'c.jpg')], params: ['width' => 800]);
$job = $photos->createJob('run', ['a.jpg'], recipe: file_get_contents('my-recipe.toml'));
```

The operations are `run`, `remove-background`, `resize`, `upscale`, `ocr`,
`barcodes`, `check`, `fix`, `remove-text`, `label`, `enhance`, `crop`,
`palette`, `watermark`, `expand`, `vectorize`, `faces`, `blur-faces`,
`id-photo`, `denoise`, `colorize`, `select`, `restore-faces` and `caption`.
A job item is one image, so a `watermark` job takes `text` only and a
`remove-background` job no background image (`model` and `studio` it takes as
the method does: `params: ['model' => 'lumen', 'studio' => true]`).

An option that repeats is a list. For `select`, each point is the string
`"x,y"`:

```php
$job = $photos->createJob('select', ['https://example.com/photos/1.jpg'], params: [
    'point' => ['420,310', '455,290'],
    'exclude' => ['300,500'],
    'mask' => true,
]);
```

The other job methods:

```php
$job = $photos->getJob($id);     // status, counts, items
$jobs = $photos->listJobs();     // recent jobs, newest first

$item = $photos->jobItemResult($id, 0);
if ($item->isImage()) {
    $image = $item->image();     // an ImageResult
} else {
    $data = $item->json();       // check, barcodes, ocr, label, palette, faces, caption
}
$item->body;                     // the raw body, whatever it is
$item->contentType;
$item->headers;
```

The items of `remove-text` and `fix` jobs carry the same extras as the
synchronous methods:

```php
$clean = $photos->jobItemResult($id, 0)->removeText();  // a RemoveTextResult
echo $clean->removed, ' removed, ', $clean->kept, " kept\n";

$fixed = $photos->jobItemResult($id, 0)->fix();         // a FixResult
echo $fixed->passed ? 'passes' : implode(', ', $fixed->findings), "\n";
```

So do the items of `remove-background`, `crop`, `vectorize`, `blur-faces`,
`id-photo`, `select` and `restore-faces` jobs, through `removeBackground()`,
`crop()`, `vectorize()`, `blurFaces()`, `idPhoto()`, `select()` and
`restoreFaces()`:

```php
$hidden = $photos->jobItemResult($id, 0)->blurFaces();  // a BlurFacesResult
echo $hidden->faces, " faces hidden\n";

$svg = $photos->jobItemResult($id, 0)->vectorize();     // a VectorizeResult
$svg->save('logo.svg');
```

They throw a `PhotosException` when the result does not carry those headers
(the job ran another operation), rather than report zeros. The exception is
`removeBackground()`, whose `subjectFound` is `null` when the API did not say.
The same headers are in the job itself, as `$job['items'][$n]['headers']` with
lower-case names.

A failed item has an `error` with a `code` and `message`; besides the usual codes
it can be `input_unavailable` (the URL could not be fetched).

`waitForJob()` throws a `WaitTimeoutException` when the job has not completed
in time; the job keeps running. A job is `completed` once every item was
attempted: look at `counts` and each item's `status` for failures.

## Webhooks

Pass `webhookUrl` when creating a job and Photos.sh posts a signed
`job.completed` event to it. Verify the signature before trusting the body.
The helper needs no client:

```php
use PhotosSh\Exception\WebhookException;
use PhotosSh\Webhook;

$payload = (string) file_get_contents('php://input');  // the raw body, untouched
$signature = $_SERVER['HTTP_PHOTOS_SIGNATURE'] ?? '';
$secret = (string) getenv('PHOTOS_WEBHOOK_SECRET');    // from $photos->webhookSecret()['secret']

try {
    $event = Webhook::verify($payload, $signature, $secret);
} catch (WebhookException $e) {
    http_response_code(400);
    exit;
}

if ($event['type'] === 'job.completed') {
    $job = $event['job'] ?? [];  // the same shape getJob() returns
}
```

`verify()` rejects deliveries whose timestamp is more than 300 seconds from
now; change that with `tolerance:`. Your signing key:

```php
$secret = $photos->webhookSecret()['secret'];
```

## Errors

Everything the library throws extends `PhotosSh\Exception\PhotosException`.

| Exception | When |
|---|---|
| `ApiException` | The API answered with an error. Has `status`, `errorCode`, `retryAfter`, `body`, and the API's message as `getMessage()`. |
| `UnauthorizedException` | 401 `unauthorized`: missing or invalid API key. |
| `PaymentRequiredException` | 402 `payment_required`: out of credits. |
| `AccountSuspendedException` | 403 `account_suspended`. |
| `NotFoundException` | 404 `not_found`: no such job, or no result for that item. |
| `BusyException` | 503 `busy`: all workers busy, after the client's own retries. |
| `ConnectionException` | No response: DNS, refused or broken connection. |
| `TimeoutException` | The request timed out (a `ConnectionException`). |
| `WaitTimeoutException` | `waitForJob()` gave up waiting. |
| `WebhookException` | A webhook delivery failed verification. |
| `InvalidInputException` | The call cannot be made: no API key, an unreadable file, no images. |

The five `ApiException` subclasses cover the cases worth their own `catch`.
For the rest, compare `errorCode` with the constants on `ApiException`:
`BAD_REQUEST`, `PAYLOAD_TOO_LARGE`, `UNSUPPORTED_FORMAT`,
`UNPROCESSABLE`, `MODEL_UNAVAILABLE`, `INTERNAL`.

```php
use PhotosSh\Exception\ApiException;
use PhotosSh\Exception\BusyException;
use PhotosSh\Exception\ConnectionException;
use PhotosSh\Exception\PaymentRequiredException;

try {
    $result = $photos->upscale('small.jpg');
} catch (PaymentRequiredException $e) {
    // Out of credits: top up.
} catch (BusyException $e) {
    // Try again in $e->retryAfter seconds.
} catch (ApiException $e) {
    if ($e->errorCode === ApiException::UNSUPPORTED_FORMAT) {
        // Not an image format the service reads.
    }
    error_log("Photos.sh said {$e->status} {$e->errorCode}: {$e->getMessage()}");
} catch (ConnectionException $e) {
    // The service could not be reached, or the request timed out.
}
```

## Configuration

```php
use PhotosSh\Photos;
use PhotosSh\Transport\CurlTransport;

$photos = new Photos(
    apiKey: 'your-api-key',             // default: the PHOTOS_API_KEY environment variable
    baseUrl: 'https://api.photos.sh',   // default: PHOTOS_BASE_URL, then https://api.photos.sh
    timeout: 120.0,                     // seconds for one request
    maxRetries: 2,                      // extra attempts; 0 turns retries off
    transport: new CurlTransport([CURLOPT_PROXY => 'http://proxy.internal:3128']),
);
```

**Retries.** A `503 busy` (or a `503` that is not the API's own error, such as
a proxy's) is retried after the `Retry-After` the server gives (at
most 30 seconds), or after 1 and then 2 seconds. A failed connection is retried
the same way, except when creating a job (so that a job is never created
twice). Timeouts, `503 model_unavailable` and all other statuses are not
retried.

### Another HTTP client

Implement `PhotosSh\Transport\Transport`: one method that sends a ready-made
request (method, URL, headers, body string, timeout) and returns the status,
headers and body. Do not follow redirects or retry, return error statuses as
responses, and throw `ConnectionException` (or `TimeoutException`) when no
response arrives. For WordPress:

```php
use PhotosSh\Exception\ConnectionException;
use PhotosSh\Photos;
use PhotosSh\Transport\Request;
use PhotosSh\Transport\Response;
use PhotosSh\Transport\Transport;

final class WordPressTransport implements Transport
{
    public function send(Request $request): Response
    {
        $result = wp_remote_request($request->url, [
            'method' => $request->method,
            'headers' => $request->headers,
            'body' => $request->body,
            'timeout' => $request->timeout,
            'redirection' => 0,
        ]);
        if (is_wp_error($result)) {
            throw new ConnectionException($result->get_error_message());
        }

        $headers = [];
        foreach (wp_remote_retrieve_headers($result) as $name => $value) {
            $headers[$name] = is_array($value) ? implode(', ', $value) : $value;
        }

        return new Response(
            (int) wp_remote_retrieve_response_code($result),
            $headers,
            wp_remote_retrieve_body($result),
        );
    }
}

$photos = new Photos(transport: new WordPressTransport());
```

## Development

```bash
composer install
composer test        # unit tests and the cURL transport against PHP's built-in server
composer stan        # PHPStan, level max
composer cs          # PHP-CS-Fixer (PER-CS), dry run; composer cs:fix applies it
```

Integration tests run against a real engine and are skipped unless both
variables are set:

```bash
PHOTOS_TEST_BASE_URL=http://127.0.0.1:8098/api PHOTOS_TEST_API_KEY=... composer test:integration
```

## License

MIT. See [LICENSE](LICENSE).
