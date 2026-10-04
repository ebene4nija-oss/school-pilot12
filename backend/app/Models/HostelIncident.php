<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Hostel management (TASK 17). Field meanings are in the hostel migration. */
class HostelIncident extends Model
{
    use BelongsToTenant;

    protected $table = 'hostel_incidents';

    protected $fillable = [
        'school_id',
        'reference',
        'hostel_id',
        'occurred_at',
        'location',
        'category',
        'description',
        'immediate_action',
        'reported_by',
        'reviewer_id',
        'guardian_notified_at',
        'status',
        'outcome',
    ];

    protected $casts = [
        'description' => 'encrypted',
        'immediate_action' => 'encrypted',
        'outcome' => 'encrypted',
        'occurred_at' => 'datetime',
        'guardian_notified_at' => 'datetime',
    ];
}
