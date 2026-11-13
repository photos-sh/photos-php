<?php

declare(strict_types=1);

namespace PhotosSh\Exception;

/**
 * 401 `unauthorized`: the API key is missing or not valid.
 */
class UnauthorizedException extends ApiException {}
