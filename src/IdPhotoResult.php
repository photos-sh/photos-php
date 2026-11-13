<?php

declare(strict_types=1);

namespace PhotosSh;

use PhotosSh\Transport\Response;

/**
 * The image from {@see Photos::idPhoto()}, with what the tool could see is
 * wrong with it.
 */
final class IdPhotoResult extends ImageResult
{
    /**
     * Whether the findings list is empty (`X-Id-Photo-Passed`). Not an
     * approval: expression, glasses, shadows, focus and likeness are not judged.
     */
    public readonly bool $passed;

    /**
     * @var list<string> codes of what was found (`X-Id-Photo-Findings`): `head_tilted`, `not_facing_camera`,
     *                   `head_cut_off`, `not_enough_room`, `low_resolution`, `background_not_plain`
     */
    public readonly array $findings;

    /** The head's height in the result, chin to crown, in millimetres (`X-Id-Photo-Head-Mm`); null when absent. */
    public readonly ?float $headMm;

    public function __construct(Response $response)
    {
        parent::__construct($response);
        $this->passed = $this->boolHeader('X-Id-Photo-Passed') ?? false;
        $this->findings = $this->listHeader('X-Id-Photo-Findings');
        $this->headMm = $this->floatHeader('X-Id-Photo-Head-Mm');
    }
}
