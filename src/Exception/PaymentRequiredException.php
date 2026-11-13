<?php

declare(strict_types=1);

namespace PhotosSh\Exception;

/**
 * 402 `payment_required`: the account is out of credits.
 */
class PaymentRequiredException extends ApiException {}
