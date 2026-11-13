<?php

declare(strict_types=1);

namespace PhotosSh;

use PhotosSh\Transport\Response;

/**
 * The SVG from {@see Photos::vectorize()}. `bytes` is the SVG's text,
 * `contentType` is `image/svg+xml`, and `width` and `height` are the size
 * the image was traced at.
 */
final class VectorizeResult extends ImageResult
{
    /** How many paths the SVG has (`X-Paths`). */
    public readonly int $paths;

    public function __construct(Response $response)
    {
        parent::__construct($response);
        $this->paths = $this->intHeader('X-Paths') ?? 0;
    }
}
