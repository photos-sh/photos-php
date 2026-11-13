<?php

declare(strict_types=1);

namespace PhotosSh\Exception;

use PhotosSh\Transport\Response;

/**
 * The API answered with an error status.
 *
 * Branch on the subclass ({@see UnauthorizedException},
 * {@see PaymentRequiredException}, {@see AccountSuspendedException},
 * {@see NotFoundException}, {@see BusyException}) or on {@see $errorCode} with the constants below.
 */
class ApiException extends PhotosException
{
    public const UNAUTHORIZED = 'unauthorized';
    public const PAYMENT_REQUIRED = 'payment_required';
    public const ACCOUNT_SUSPENDED = 'account_suspended';
    public const BAD_REQUEST = 'bad_request';
    public const NOT_FOUND = 'not_found';
    public const PAYLOAD_TOO_LARGE = 'payload_too_large';
    public const UNSUPPORTED_FORMAT = 'unsupported_format';
    public const UNPROCESSABLE = 'unprocessable';
    public const BUSY = 'busy';
    public const MODEL_UNAVAILABLE = 'model_unavailable';
    public const INTERNAL = 'internal';

    /** Only on a failed job item: its input URL could not be fetched. */
    public const INPUT_UNAVAILABLE = 'input_unavailable';

    /**
     * @param int         $status     HTTP status
     * @param string|null $errorCode  the API's error code, or null when the body carried none
     * @param string      $message    the API's message, or the start of a non-JSON body
     * @param int|null    $retryAfter seconds from the `Retry-After` header, when present
     * @param string      $body       the raw response body
     */
    final public function __construct(
        public readonly int $status,
        public readonly ?string $errorCode,
        string $message,
        public readonly ?int $retryAfter = null,
        public readonly string $body = '',
    ) {
        parent::__construct($message, $status);
    }

    /**
     * Builds the exception for an error response, picking the subclass from
     * the API's error code (or, without one, from the status).
     */
    public static function fromResponse(Response $response): self
    {
        $code = null;
        $message = null;
        $decoded = json_decode($response->body, true);
        if (is_array($decoded) && is_array($decoded['error'] ?? null)) {
            $error = $decoded['error'];
            if (is_string($error['code'] ?? null)) {
                $code = $error['code'];
            }
            if (is_string($error['message'] ?? null)) {
                $message = $error['message'];
            }
        }
        if ($message === null) {
            $start = trim(substr($response->body, 0, 200));
            $message = $start !== '' ? $start : 'HTTP ' . $response->status;
        }

        $retryAfter = null;
        $header = $response->header('Retry-After');
        if ($header !== null && ctype_digit(trim($header))) {
            $retryAfter = (int) trim($header);
        }

        $class = match ($code ?? self::codeOfStatus($response->status)) {
            self::UNAUTHORIZED => UnauthorizedException::class,
            self::PAYMENT_REQUIRED => PaymentRequiredException::class,
            self::ACCOUNT_SUSPENDED => AccountSuspendedException::class,
            self::NOT_FOUND => NotFoundException::class,
            self::BUSY => BusyException::class,
            default => self::class,
        };

        return new $class($response->status, $code, $message, $retryAfter, $response->body);
    }

    private static function codeOfStatus(int $status): ?string
    {
        return match ($status) {
            401 => self::UNAUTHORIZED,
            402 => self::PAYMENT_REQUIRED,
            403 => self::ACCOUNT_SUSPENDED,
            default => null,
        };
    }
}
