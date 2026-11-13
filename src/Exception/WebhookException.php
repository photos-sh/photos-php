<?php

declare(strict_types=1);

namespace PhotosSh\Exception;

/**
 * A webhook delivery failed verification: a malformed or missing signature,
 * a signature that does not match, a timestamp outside the tolerance, or a
 * body that is not a JSON event.
 */
class WebhookException extends PhotosException {}
