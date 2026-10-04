<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Hostel management (TASK 17). Field meanings are in the hostel migration. */
class HostelMaintenanceRequest extends Model
{
    use BelongsToTenant;

    protected $table = 'hostel_maintenance_requests';

    protected $fillable = [
        'school_id',
        'reference',
        'hostel_id',
        'room_id',
        'bed_id',
        'asset_id',
        'category',
        'description',
        'priority',
        'reported_by',
        'assigned_to',
        'status',
        'work_notes',
        'took_out_of_service',
        'completed_at',
        'verified_by',
        'verified_at',
    ];

    protected $casts = [
        'took_out_of_service' => 'boolean',
        'completed_at' => 'datetime',
        'verified_at' => 'datetime',
    ];
}
