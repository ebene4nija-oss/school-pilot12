<?php

namespace App\Exceptions;

use LogicException;

/**
 * A tenant-scoped model was queried with no school established.
 *
 * A programming error, not a user error: the caller must either run inside a
 * request/job that knows its school, or opt out of scoping explicitly.
 */
class TenantNotEstablished extends LogicException
{
    public function __construct(string $model)
    {
        parent::__construct("No school is established for a query on {$model}. Run it inside TenantContext::forSchool(), or withoutScoping() if it is genuinely cross-tenant.");
    }
}
