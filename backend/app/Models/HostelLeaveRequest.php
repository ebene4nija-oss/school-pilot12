<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Hostel management (TASK 17). Field meanings are in the hostel migration. */
class HostelLeaveRequest extends Model
{
    use BelongsToTenant;

    protected $table = 'hostel_leave_requests';

    protected $fillable = [
        'school_id',
        'student_id',
        'leave_type',
        'reason',
        'depart_at',
        'return_at',
        'destination',
        'pickup_person',
        'guardian_consent',
        'requested_by_type',
        'requested_by',
        'status',
        'reviewed_by',
        'reviewed_at',
        'decision_note',
    ];

    protected $casts = [
        'depart_at' => 'datetime',
        'return_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }
}
