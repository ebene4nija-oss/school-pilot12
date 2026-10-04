<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Hostel management (TASK 17). Field meanings are in the hostel migration. */
class HostelRollcallSession extends Model
{
    use BelongsToTenant;

    protected $table = 'hostel_rollcall_sessions';

    protected $fillable = [
        'school_id',
        'hostel_id',
        'name',
        'time',
        'days',
        'grace_minutes',
        'is_active',
    ];

    protected $casts = [
        'days' => 'array',
        'is_active' => 'boolean',
    ];
}
