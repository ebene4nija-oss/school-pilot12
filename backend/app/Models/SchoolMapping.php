<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** How another school's class or subject name maps onto this school's. */
class SchoolMapping extends Model
{
    use BelongsToTenant;

    public const TYPES = ['exact', 'equivalent', 'different_name', 'not_offered', 'needs_assessment'];

    protected $fillable = ['school_id', 'kind', 'external_name', 'local_id', 'mapping_type'];
}
