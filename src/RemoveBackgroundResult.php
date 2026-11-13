<?php

declare(strict_types=1);

namespace PhotosSh;

use PhotosSh\Transport\Response;

/**
 * The image from {@see Photos::removeBackground()}.
 */
final class RemoveBackgroundResult extends ImageResult
{
    /**
     * False when a canvas was asked for (`width` and `height`) and the photo
     * has no subject: the canvas is then empty (`X-Subject-Found`). Null in
     * every other case: the API does not say.
     */
    public readonly ?bool $subjectFound;

    public function __construct(Response $response)
    {
        parent::__construct($response);
        $this->subjectFound = $this->boolHeader('X-Subject-Found');
    }
}
