<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Hostel management (TASK 17). Field meanings are in the hostel migration. */
class HostelDutyShift extends Model
{
    use BelongsToTenant;

    protected $table = 'hostel_duty_shifts';

    protected $fillable = [
        'school_id',
        'hostel_id',
        'user_id',
        'date',
        'shift',
        'substitute_for',
        'handover_notes',
        'published',
    ];

    protected $casts = [
        'date' => 'date',
        'published' => 'boolean',
    ];
}
