# Changelog

All notable changes to this library are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the library
follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.0.0 (unreleased)

First version.

- `identify` matches against the organisation's own catalogues (uploaded in
  the console; turned on per organisation). Without it the API answers
  `404 not_found` (`NotFoundException`). A catalogue is one of seven kinds of
  product, and the evidence and the label's values follow it: `sku`, `brand`,
  `quantity`, `pack`, `spf`, `strength`, `count`, `spec`, `model` beside the
  wine and spirits ones.
- `Photos` client: API key and base URL from arguments or the environment
  (`PHOTOS_API_KEY`, `PHOTOS_BASE_URL`), a 120-second timeout, and two retries
  on `503 busy` and on failed connections.
- Edit: `removeBackground`, `resize`, `upscale`, `erase`, `removeText`, `run`.
- Editing tools: `enhance`, `crop`, `watermark` (text, or a logo sent as a
  second image), `expand`, `vectorize` (SVG), `blurFaces`, `idPhoto` and
  `idPhotos` (the built-in documents), `denoise`, `colorize`, `select`
  (points to include and to exclude, a box) and `restoreFaces`.
- `removeBackground` stages the cutout in the same call: `width`, `height`,
  `fill`, `align`, `shadow`, `reflection`, `blur`, and `backgroundImage` for
  an image behind the subject.
- `resize` takes `trim`, `trimTolerance` and `dpi`.
- Check and fix: `check`, `fix`, `marketplaces`.
- Read: `readBarcodes`, `readText`, `readLabel`, `identify`, `palette`,
  `findFaces`, `caption`.
- Account: `account`, `presets`, `health`.
- Image results that say more than the image: `RemoveTextResult` (`removed`,
  `kept`), `FixResult` (`passed`, `findings`), `RemoveBackgroundResult` and
  `CropResult` (`subjectFound`: true, false, or null when the API does not
  say), `VectorizeResult` (`paths`), `BlurFacesResult` (`faces`),
  `IdPhotoResult` (`passed`, `findings`, `headMm`), `SelectResult` (`score`),
  `RestoreFacesResult` (`faces`, `facesSkipped`).
- Batch jobs: `createJob`, `getJob`, `listJobs`, `deleteJob`, `jobItemResult`,
  `waitForJob`, `webhookSecret`. A job item result is a `JobItemResult` with
  `image()`, `json()`, and an accessor for each result above (`removeText()`,
  `fix()`, `removeBackground()`, `crop()`, `vectorize()`, `blurFaces()`,
  `idPhoto()`, `select()`, `restoreFaces()`). A job option that repeats
  (`point` and `exclude` of `select`) is given as a list in `params`.
- `Webhook::verify()` for signed webhook deliveries.
- `Input` for images from a path, bytes or a stream.
- A `Transport` interface with a cURL implementation, to plug in another HTTP
  client.
