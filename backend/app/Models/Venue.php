<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A place lessons happen: a classroom, lab, hall or field (TASK 07). */
class Venue extends Model
{
    use BelongsToTenant;

    protected $fillable = ['school_id', 'name', 'kind', 'capacity', 'class_id', 'shared', 'active'];

    protected $casts = [
        'capacity' => 'integer',
        'class_id' => 'integer',
        'shared' => 'boolean',
        'active' => 'boolean',
    ];
}
