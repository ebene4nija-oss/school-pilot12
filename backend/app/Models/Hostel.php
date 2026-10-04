<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Hostel management (TASK 17). Field meanings are in the hostel migration. */
class Hostel extends Model
{
    use BelongsToTenant;

    protected $table = 'hostels';

    protected $fillable = [
        'school_id',
        'name',
        'code',
        'campus',
        'placement_policy',
        'status',
        'notes',
    ];
}
