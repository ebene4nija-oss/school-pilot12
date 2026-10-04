<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Hostel management (TASK 17). Field meanings are in the hostel migration. */
class HostelStaffAssignment extends Model
{
    use BelongsToTenant;

    protected $table = 'hostel_staff_assignments';

    protected $fillable = [
        'school_id',
        'hostel_id',
        'user_id',
        'role',
        'starts_on',
        'ends_on',
    ];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
    ];
}
