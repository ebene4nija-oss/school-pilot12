<?php

namespace App\Services;

use App\Models\AcademicSession;
use App\Models\AuditLog;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Making a child a student of a school: their account, their record, their
 * enrolment in the current session and their guardians.
 *
 * One path for every way a child arrives — the enrolment form, an admission
 * offer accepted, an incoming transfer approved — so a student created by any
 * of them is the same shape and numbered in the same series.
 */
class StudentEnrolmentService
{
    public function __construct(private GuardianDirectory $directory)
    {
    }

    /**
     * Validation is the caller's job; this writes.
     *
     * @param  array<string, mixed>  $data  validated enrolment fields
     * @return array{0: Student, 1: array<int, User>} the student, and guardian accounts created
     */
    public function enrol(int $schoolId, array $data, User $admin, ?string $photoPath = null, ?string $ip = null, ?string $userAgent = null, string $auditAction = 'student.created'): array
    {
        return DB::transaction(function () use ($schoolId, $data, $admin, $photoPath, $ip, $userAgent, $auditAction) {
            /*
             * Never seen by anyone, including the admin creating the record.
             * It exists so the row is never briefly stored with a guessable
             * password; the student receives a setup link instead.
             */
            $tempPassword = bin2hex(random_bytes(32));

            $user = User::create([
                'name' => $data['name'],
                'email' => filled($data['email'] ?? null) ? strtolower(trim($data['email'])) : null,
                'password' => Hash::make($tempPassword),
            ]);

            UserProfile::create([
                'school_id' => $schoolId,
                'user_id' => $user->id,
                'role' => 'student',
                'phone' => GuardianDirectory::canonicalPhone($data['phone'] ?? null),
                'avatar_url' => $photoPath,
            ]);

            // One series however each child was enrolled.
            $admission = filled($data['admission_number'] ?? null)
                ? trim($data['admission_number'])
                : $this->nextAdmissionNumber($schoolId);

            $student = Student::create([
                'school_id' => $schoolId,
                'user_id' => $user->id,
                'class_id' => $data['class_id'] ?? null,
                'arm_id' => $data['arm_id'] ?? null,
                'admission_number' => $admission,
                'gender' => $data['gender'] ?? null,
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'state_of_origin' => $data['state_of_origin'] ?? null,
                'lga' => $data['lga'] ?? null,
                'religion' => $data['religion'] ?? null,
                'previous_school' => $data['previous_school'] ?? null,
                'blood_group' => $data['blood_group'] ?? null,
                'allergies' => $data['allergies'] ?? null,
                'medical_notes' => $data['medical_notes'] ?? null,
                'birth_certificate_reference' => $data['birth_certificate_reference'] ?? null,
                'passport_photo_path' => $photoPath,
                'emergency_contacts' => $data['emergency_contacts'] ?? null,
                'status' => 'active',
            ]);

            // Joins the current session straight away, or the next rollover
            // would skip a child enrolled mid-year.
            $sessionId = AcademicSession::where('school_id', $schoolId)->where('is_current', true)->value('id');

            if ($sessionId && ($data['class_id'] ?? null)) {
                StudentEnrollment::create([
                    'school_id' => $schoolId,
                    'student_id' => $student->id,
                    'session_id' => $sessionId,
                    'class_id' => $data['class_id'],
                    'arm_id' => $data['arm_id'] ?? null,
                    'status' => 'active',
                    'enrolled_on' => now()->toDateString(),
                    'recorded_by' => $admin->id,
                ]);
            }

            $newGuardianUsers = [];

            foreach (array_values($data['guardians'] ?? []) as $index => $details) {
                $result = $this->directory->findOrCreate($schoolId, $details);

                if (! $student->guardians()->whereKey($result['guardian']->id)->exists()) {
                    $student->guardians()->attach($result['guardian']->id, ['is_primary' => $index === 0]);
                }

                if ($result['created']) {
                    $newGuardianUsers[] = $result['guardian']->user;
                }
            }

            AuditLog::create([
                'school_id' => $schoolId,
                'user_id' => $admin->id,
                'action' => $auditAction,
                'auditable_type' => Student::class,
                'auditable_id' => $student->id,
                'ip_address' => $ip,
                'user_agent' => $userAgent,
            ]);

            return [$student, $newGuardianUsers];
        });
    }

    /** Next free number in the school's `YYYY/NNNN` series. */
    public function nextAdmissionNumber(int $schoolId): string
    {
        $sequence = (int) Student::withoutGlobalScopes()->where('school_id', $schoolId)->count();

        do {
            $sequence++;
            $candidate = sprintf('%s/%04d', now()->format('Y'), $sequence);
        } while (Student::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('admission_number', $candidate)
            ->exists());

        return $candidate;
    }

    /**
     * Existing students who may be this child, for a person to judge.
     * Nothing is ever merged automatically.
     *
     * @param  array{name?: string, date_of_birth?: string|null, admission_number?: string|null, guardian_phone?: string|null}  $details
     * @return array<int, array<string, mixed>>
     */
    public function possibleDuplicates(int $schoolId, array $details): array
    {
        $name = mb_strtolower(trim((string) ($details['name'] ?? '')));
        $tokens = array_values(array_filter(preg_split('/\s+/', $name) ?: [], fn ($t) => mb_strlen($t) > 1));

        $candidates = DB::table('students')
            ->join('users', 'users.id', '=', 'students.user_id')
            ->where('students.school_id', $schoolId)
            ->whereNull('students.deleted_at')
            ->where(function ($q) use ($details, $tokens) {
                if (filled($details['admission_number'] ?? null)) {
                    $q->orWhere('students.admission_number', trim($details['admission_number']));
                }

                if (count($tokens) >= 2) {
                    $q->orWhere(function ($w) use ($tokens) {
                        foreach ($tokens as $t) {
                            $w->whereRaw('LOWER(users.name) LIKE ?', ['%'.$t.'%']);
                        }
                    });
                }
            })
            ->limit(20)
            ->get(['students.id', 'students.admission_number', 'students.date_of_birth', 'students.status', 'users.name']);

        $phone = GuardianDirectory::canonicalPhone($details['guardian_phone'] ?? null);

        return $candidates->map(function ($c) use ($details, $phone) {
            $reasons = [];

            if (filled($details['admission_number'] ?? null) && $c->admission_number === trim($details['admission_number'])) {
                $reasons[] = 'Same admission number';
            }
            if (filled($details['date_of_birth'] ?? null) && $c->date_of_birth && substr((string) $c->date_of_birth, 0, 10) === substr((string) $details['date_of_birth'], 0, 10)) {
                $reasons[] = 'Same date of birth';
            }
            $reasons[] = 'Similar name';

            if ($phone) {
                $shared = DB::table('student_guardian')
                    ->join('guardians', 'guardians.id', '=', 'student_guardian.guardian_id')
                    ->join('user_profiles', 'user_profiles.user_id', '=', 'guardians.user_id')
                    ->where('student_guardian.student_id', $c->id)
                    ->where('user_profiles.phone', $phone)
                    ->exists();

                if ($shared) {
                    $reasons[] = 'Same guardian phone';
                }
            }

            return [
                'student_id' => (int) $c->id,
                'name' => $c->name,
                'admission_number' => $c->admission_number,
                'status' => $c->status,
                'reasons' => array_values(array_unique($reasons)),
            ];
        })->sortByDesc(fn ($c) => count($c['reasons']))->values()->all();
    }
}
