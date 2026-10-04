<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Hostel management (TASK 17). Field meanings are in the hostel migration. */
class HostelInspection extends Model
{
    use BelongsToTenant;

    protected $table = 'hostel_inspections';

    protected $fillable = [
        'school_id',
        'room_id',
        'inspected_by',
        'inspected_at',
        'checklist',
        'result',
        'notes',
    ];

    protected $casts = [
        'checklist' => 'array',
        'inspected_at' => 'datetime',
    ];
}
