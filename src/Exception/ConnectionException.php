<?php

declare(strict_types=1);

namespace PhotosSh\Exception;

/**
 * No HTTP response was received: the host could not be resolved or reached,
 * the connection broke, or the request timed out ({@see TimeoutException}).
 */
class ConnectionException extends PhotosException {}
