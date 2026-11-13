<?php

declare(strict_types=1);

namespace PhotosSh;

use PhotosSh\Transport\Response;

/**
 * The image from {@see Photos::blurFaces()}, with how many faces were hidden.
 */
final class BlurFacesResult extends ImageResult
{
    /** Faces found and hidden (`X-Faces`). A face that was not found still shows. */
    public readonly int $faces;

    public function __construct(Response $response)
    {
        parent::__construct($response);
        $this->faces = $this->intHeader('X-Faces') ?? 0;
    }
}
