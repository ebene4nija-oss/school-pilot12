<?php

namespace App\Services\Results;

use App\Exceptions\DomainRefusal;

/** The user is signed in but this step, subject or record is not theirs (HTTP 403). */
class AuthorisationFailure extends DomainRefusal
{
}
