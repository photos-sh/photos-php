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
     * False when no subject is found (one covering under 3% of the photo
     * does not count): the photo then comes back unchanged, or with `mask`
     * the mask (`X-Subject-Found`). Null in every other case: the API does
     * not say.
     */
    public readonly ?bool $subjectFound;

    public function __construct(Response $response)
    {
        parent::__construct($response);
        $this->subjectFound = $this->boolHeader('X-Subject-Found');
    }
}
