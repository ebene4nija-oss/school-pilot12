<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Student extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

    /**
     * Enrolment states.
     *
     * Was a database enum of four values; `withdrawn` had no way in, so a
     * child who simply left was recorded as `transferred` or left `active`
     * forever, inflating every roster and head-count in the school.
     *
     * Nothing accepts a status from a request: it is always derived from an
     * action — promote, graduate, transfer out, withdraw — so this list is the
     * set of states those actions can produce, not a validation rule.
     */
    public const STATUSES = [
        'active',
        'graduated',
        'transferred',
        'withdrawn',
        'suspended',
    ];

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

    /**
     * Changes to the fields that matter are recorded with who, when and why,
     * whichever code path makes them (View Students spec §3 rule 5). Old
     * values are never lost to an overwrite.
     */
    protected static function booted(): void
    {
        static::updated(function (Student $student) {
            // Leaving the school ends the ID card (TASK 18 §22.15).
            if ($student->wasChanged('status') && $student->getOriginal('status') === 'active' && $student->status !== 'active') {
                try {
                    app(\App\Services\IdCard\IdCardLifecycleService::class)->holderLeft('student', $student->id, (int) $student->school_id, auth()->id());
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            foreach (StudentRecordChange::TRACKED as $field) {
                if (! $student->wasChanged($field)) {
                    continue;
                }

                try {
                    StudentRecordChange::withoutGlobalScopes()->create([
                        'school_id' => $student->school_id,
                        'student_id' => $student->id,
                        'field' => $field,
                        'old_value' => self::plain($student->getOriginal($field)),
                        'new_value' => self::plain($student->getAttribute($field)),
                        'reason' => StudentRecordChange::currentReason(),
                        'changed_by' => auth()->id(),
                    ]);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        });
    }

    private static function plain(mixed $value): ?string
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : ($value === null ? null : (string) $value);
    }

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

    /**
     * Placement per session — where this student actually sat, as opposed to
     * `classHistory`, which records the moments they moved.
     */
    public function enrollments()
    {
        return $this->hasMany(StudentEnrollment::class);
    }

    public function currentEnrollment()
    {
        return $this->hasOne(StudentEnrollment::class)->where('status', 'active');
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
