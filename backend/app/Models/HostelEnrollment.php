<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Hostel management (TASK 17). Field meanings are in the hostel migration. */
class HostelEnrollment extends Model
{
    use BelongsToTenant;

    protected $table = 'hostel_enrollments';

    protected $fillable = [
        'school_id',
        'student_id',
        'session_id',
        'boarding_category',
        'starts_on',
        'ends_on',
        'expected_arrival',
        'status',
        'requested_by',
        'reviewed_by',
        'reviewed_at',
        'decision_note',
    ];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
        'expected_arrival' => 'date',
        'reviewed_at' => 'datetime',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }
}
