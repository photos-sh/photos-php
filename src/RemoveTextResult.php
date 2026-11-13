<?php

declare(strict_types=1);

namespace PhotosSh;

use PhotosSh\Transport\Response;

/**
 * The image from {@see Photos::removeText()}, with what was done.
 */
final class RemoveTextResult extends ImageResult
{
    /** Lines of text painted out (`X-Text-Removed`). */
    public readonly int $removed;

    /** Lines of text left in place (`X-Text-Kept`). */
    public readonly int $kept;

    public function __construct(Response $response)
    {
        parent::__construct($response);
        $this->removed = $this->intHeader('X-Text-Removed') ?? 0;
        $this->kept = $this->intHeader('X-Text-Kept') ?? 0;
    }
}
