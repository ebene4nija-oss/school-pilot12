<?php

namespace App\Services;

use App\Exceptions\DomainRefusal;

/** Enrolment paused: existing students may be this child. A person decides. */
class DuplicateApplicant extends DomainRefusal
{
    public function __construct(public readonly array $candidates)
    {
        parent::__construct('Existing students may be this child. Check them, then confirm this is a different child to enrol.');
    }
}
