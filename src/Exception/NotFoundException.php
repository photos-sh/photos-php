<?php

declare(strict_types=1);

namespace PhotosSh\Exception;

/**
 * 404 `not_found`: no such job, no result for that job item, or (from
 * identify) catalogue matching is not on for the organisation.
 */
class NotFoundException extends ApiException {}
