<?php

declare(strict_types=1);

namespace PhotosSh\Exception;

/**
 * 503 `busy`: every worker is busy and the queue is full. Thrown once the client's own retries are used up; {@see ApiException::$retryAfter} says when to try again.
 */
class BusyException extends ApiException {}
