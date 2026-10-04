<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Hostel management (TASK 17). Field meanings are in the hostel migration. */
class HostelRollcall extends Model
{
    use BelongsToTenant;

    protected $table = 'hostel_rollcalls';

    protected $fillable = [
        'school_id',
        'session_id',
        'hostel_id',
        'date',
        'status',
        'opened_by',
        'submitted_by',
        'submitted_at',
    ];

    protected $casts = [
        'date' => 'date',
        'submitted_at' => 'datetime',
    ];
}
