<?php

declare(strict_types=1);

namespace PhotosSh\Exception;

/**
 * The call could not be made as written: no API key, an image file that
 * cannot be read, no images where at least one is needed.
 */
class InvalidInputException extends PhotosException {}
