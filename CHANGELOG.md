# Changelog

All notable changes to this library are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the library
follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

## 1.3.0 (2026-11-20)

- `removeBackground` takes `model`, the model that finds the subject: `atlas`
  (the API's default) for everyday product photos, `lumen` for pale packaging
  on a light backdrop and close-ups where the product fills the photo, `muse`
  for people. Each costs the same. It is a string, so a model the API adds
  later works without a new release; it is sent only when given, and works
  with `studio`. A `remove-background` job takes it in `params`. Needs engine
  0.1.14.

## 1.2.1 (2026-11-16)

- `Photos::VERSION` (sent in the User-Agent) says the library's version: it
  said 1.0.0 in 1.0.0 to 1.2.0.

## 1.2.0 (2026-11-16)

- `run` recipes can read the photo: what the steps `[barcodes]` and `[text]`
  read comes back on the image result as `barcodes` (the same shape as
  `readBarcodes` gives), `text` (the lines, top to bottom) and
  `textTruncated`, from the `X-Barcodes`, `X-Text` and `X-Text-Truncated`
  headers. `barcodes` and `text` are null when the recipe has no such step
  (or the header cannot be read) and empty when the step found nothing. A
  `run` job item's `image()` carries them too. Needs the engine release with
  reading steps (Vallic/photos#7); an older engine answers `422`.
- `preset(name)` returns a built-in preset's recipe as TOML, to start a recipe
  of your own from. An unknown name is `404 not_found` (`NotFoundException`).

## 1.1.0 (2026-11-16)

- `removeBackground` takes `studio`: for studio shots (a product on a plain,
  light backdrop, as in a light tent), also keep what clearly stands out from
  the backdrop and touches the product, such as the gift box, case or lid
  shown with a bottle. Shadows and the floor are left out. Needs engine
  0.1.10.
- When no subject is found (one covering under 3% of the photo does not
  count), `remove-background` returns the photo unchanged, with or without a
  canvas, and `subjectFound` is `false`. The docs no longer say the canvas
  comes back empty.

## 1.0.0 (2026-11-13)

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
