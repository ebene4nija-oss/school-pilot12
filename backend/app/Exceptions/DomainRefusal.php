<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A business rule said no: "this broadsheet is not under review", "consent
 * has not been given". The message is written for the person using the app
 * and becomes a 409.
 *
 * Its own type so controllers catch only refusals. Catching RuntimeException
 * instead also caught database errors (whose messages carry SQL) and
 * ModelNotFoundException (which should stay a 404).
 */
class DomainRefusal extends RuntimeException
{
}
