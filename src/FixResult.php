<?php

declare(strict_types=1);

namespace PhotosSh;

use PhotosSh\Transport\Response;

/**
 * The image from {@see Photos::fix()}, with the marketplace's verdict on it.
 */
final class FixResult extends ImageResult
{
    /** Whether the fixed image passes the marketplace's check (`X-Marketplace-Passed`). */
    public readonly bool $passed;

    /** @var list<string> codes of the findings still present (`X-Marketplace-Findings`) */
    public readonly array $findings;

    public function __construct(Response $response)
    {
        parent::__construct($response);
        $this->passed = $this->boolHeader('X-Marketplace-Passed') ?? false;
        $this->findings = $this->listHeader('X-Marketplace-Findings');
    }
}
