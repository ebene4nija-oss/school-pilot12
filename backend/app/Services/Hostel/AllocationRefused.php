<?php

namespace App\Services\Hostel;

use App\Exceptions\DomainRefusal;

/** An allocation refused, naming the field at fault for form errors. */
class AllocationRefused extends DomainRefusal
{
    public function __construct(string $message, public readonly string $field)
    {
        parent::__construct($message);
    }
}
