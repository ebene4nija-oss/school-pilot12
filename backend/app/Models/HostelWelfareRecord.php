<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Hostel management (TASK 17). Field meanings are in the hostel migration. */
class HostelWelfareRecord extends Model
{
    use BelongsToTenant;

    protected $table = 'hostel_welfare_records';

    protected $fillable = [
        'school_id',
        'student_id',
        'category',
        'description',
        'action_taken',
        'priority',
        'recorded_by',
        'assigned_to',
        'follow_up_on',
        'status',
        'closed_at',
    ];

    protected $casts = [
        'description' => 'encrypted',
        'action_taken' => 'encrypted',
        'follow_up_on' => 'date',
        'closed_at' => 'datetime',
    ];
}
