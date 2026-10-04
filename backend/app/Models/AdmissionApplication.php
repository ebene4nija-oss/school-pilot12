<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An applicant: not a student until enrolment. */
class AdmissionApplication extends Model
{
    use BelongsToTenant;

    public const STATUSES = [
        'submitted' => 'Submitted',
        'under_review' => 'Under review',
        'assessment' => 'Assessment or interview',
        'waitlisted' => 'Waitlisted',
        'offered' => 'Offer made',
        'accepted' => 'Offer accepted',
        'declined' => 'Offer declined',
        'expired' => 'Offer expired',
        'rejected' => 'Not admitted',
        'withdrawn' => 'Withdrawn',
        'enrolled' => 'Enrolled',
    ];

    /** Still in progress: personal data is kept while one of these. */
    public const ACTIVE = ['submitted', 'under_review', 'assessment', 'waitlisted', 'offered', 'accepted'];

    protected $fillable = [
        'school_id', 'reference', 'section', 'session_id', 'intended_class_id', 'applicant_name', 'date_of_birth',
        'gender', 'previous_school', 'previous_class', 'guardians', 'source', 'status', 'decision_reason',
        'decided_by', 'decided_at', 'offered_class_id', 'offer_expires_at', 'responded_at', 'student_id',
        'enrolled_at', 'purged_at',
    ];

    protected $casts = [
        'guardians' => 'encrypted:array',
        'date_of_birth' => 'date',
        'decided_at' => 'datetime',
        'offer_expires_at' => 'datetime',
        'responded_at' => 'datetime',
        'enrolled_at' => 'datetime',
        'purged_at' => 'datetime',
    ];

    public function documents()
    {
        return $this->hasMany(AdmissionDocument::class, 'application_id');
    }

    public function assessments()
    {
        return $this->hasMany(AdmissionAssessment::class, 'application_id')->orderBy('id');
    }

    public function events()
    {
        return $this->hasMany(AdmissionEvent::class, 'application_id')->orderBy('id');
    }
}
