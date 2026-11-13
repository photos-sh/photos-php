<?php

declare(strict_types=1);

namespace PhotosSh;

use PhotosSh\Transport\Response;

/**
 * The cutout or mask from {@see Photos::select()}.
 */
final class SelectResult extends ImageResult
{
    /** How well the model thinks the mask fits the object, 0 to 1 (`X-Select-Score`); null when absent. */
    public readonly ?float $score;

    public function __construct(Response $response)
    {
        parent::__construct($response);
        $this->score = $this->floatHeader('X-Select-Score');
    }
}
