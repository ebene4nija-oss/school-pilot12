<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Student extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

    protected $fillable = [
        'school_id',
        'user_id',
        'class_id',
        'arm_id',
        'admission_number',
        'date_of_birth',
        'gender',
        'state_of_origin',
        'lga',
        'religion',
        'birth_certificate_reference',
        'passport_photo_path',
        'blood_group',
        'allergies',
        'medical_notes',
        'emergency_contacts',
        'previous_school',
        'status',
    ];

    /**
     * NDPA Compliance: Application-level encryption for sensitive personal/health data
     */
    protected $casts = [
        'blood_group' => 'encrypted',
        'allergies' => 'encrypted:array',
        'medical_notes' => 'encrypted',
        'emergency_contacts' => 'encrypted:array',
        'date_of_birth' => 'date',
    ];

    /**
     * Health data never serialises by default.
     *
     * The four encrypted columns above are encrypted *at rest*. That protects a
     * stolen database dump and nothing else: Eloquent decrypts on access, so
     * any `response()->json($student)` handed the plaintext straight back. The
     * roster listing is open to `role:teacher` and returns twenty students a
     * page, so every teacher in the school was receiving every child's
     * medical notes and emergency contacts without asking for them.
     *
     * `$hidden` is the safety net rather than the mechanism — 41 call sites
     * touch this model and any of them could serialise it. Code that genuinely
     * needs these fields reads the attributes directly (unaffected by
     * `$hidden`) or opts in explicitly via `StudentMedicalResource`, which is
     * reachable only through the audited endpoint in StudentController.
     */
    protected $hidden = [
        'blood_group',
        'allergies',
        'medical_notes',
        'emergency_contacts',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function currentClass()
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function currentArm()
    {
        return $this->belongsTo(Arm::class, 'arm_id');
    }

    public function classHistory()
    {
        return $this->hasMany(StudentClassHistory::class)->orderByDesc('created_at');
    }

    public function guardians()
    {
        return $this->belongsToMany(Guardian::class, 'student_guardian');
    }

    /**
     * Whether the given user is a guardian of record for this student.
     * This is the check that gates parent access to a child's data — school
     * membership alone is not sufficient (see docs/TECHNICAL-REVIEW §2.1).
     */
    public function isGuardedBy(User $user): bool
    {
        return $this->guardians()
            ->where('guardians.user_id', $user->id)
            ->exists();
    }
}
