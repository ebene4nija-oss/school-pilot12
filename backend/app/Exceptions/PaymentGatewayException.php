<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The gateway refused, or could not be reached, while opening a checkout.
 *
 * Deliberately its own type so callers can tell "the school's Paystack account
 * rejected this" from a bug in our own code, and answer the client with a 502
 * plus a message a bursar can act on rather than a generic 500.
 */
class PaymentGatewayException extends RuntimeException
{
}
