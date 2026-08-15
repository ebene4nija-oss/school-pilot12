<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentEnrollment extends Model
{
    use BelongsToTenant;

    /** How a session ended for a student. */
    public const OUTCOMES = [
        'promoted',
        'repeated',
        'graduated',
        'transferred_out',
        'withdrawn',
    ];

    /**
     * The outcomes that end a student's time at the school rather than moving
     * them along inside it. These are the ones that must not create a
     * next-session enrolment.
     */
    public const EXIT_OUTCOMES = [
        'graduated',
        'transferred_out',
        'withdrawn',
    ];

    protected $fillable = [
        'school_id',
        'student_id',
        'session_id',
        'class_id',
        'arm_id',
        'status',
        'outcome',
        'enrolled_on',
        'closed_on',
        'destination_school',
        'outcome_remarks',
        'recorded_by',
    ];

    protected $casts = [
        'enrolled_on' => 'date',
        'closed_on' => 'date',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function session()
    {
        return $this->belongsTo(AcademicSession::class, 'session_id');
    }

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function arm()
    {
        return $this->belongsTo(Arm::class, 'arm_id');
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function scopeOpen($query)
    {
        return $query->where('status', 'active');
    }
}
