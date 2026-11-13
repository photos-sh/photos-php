<?php

declare(strict_types=1);

namespace PhotosSh;

use PhotosSh\Transport\Response;

/**
 * The image from {@see Photos::crop()}.
 */
final class CropResult extends ImageResult
{
    /**
     * Whether a subject (or, with `focus: 'face'`, a face) was found
     * (`X-Subject-Found`). When false the crop is centred. Null when the
     * API did not say.
     */
    public readonly ?bool $subjectFound;

    public function __construct(Response $response)
    {
        parent::__construct($response);
        $this->subjectFound = $this->boolHeader('X-Subject-Found');
    }
}
