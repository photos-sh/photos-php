<?php

declare(strict_types=1);

namespace PhotosSh\Exception;

/**
 * The request did not finish within the client's timeout. Not retried: the
 * server may still be working on it.
 */
class TimeoutException extends ConnectionException {}
