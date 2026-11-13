<?php

declare(strict_types=1);

namespace PhotosSh;

use PhotosSh\Transport\Response;

/**
 * The image from {@see Photos::restoreFaces()}, with what was done.
 */
final class RestoreFacesResult extends ImageResult
{
    /** Faces restored (`X-Faces`). */
    public readonly int $faces;

    /** Faces left alone because they are large and detailed enough (`X-Faces-Skipped`). */
    public readonly int $facesSkipped;

    public function __construct(Response $response)
    {
        parent::__construct($response);
        $this->faces = $this->intHeader('X-Faces') ?? 0;
        $this->facesSkipped = $this->intHeader('X-Faces-Skipped') ?? 0;
    }
}
