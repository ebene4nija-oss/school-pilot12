<?php

namespace App\Services;

use App\Exceptions\DomainRefusal;
use App\Models\AdmissionApplication;
use App\Models\AdmissionDocument;
use App\Models\AdmissionEvent;
use App\Models\AdmissionSetting;
use App\Models\Student;
use App\Models\User;
use App\Support\Sections;
use App\Support\TeacherScope;
use Illuminate\Support\Facades\DB;

/**
 * The admissions pipeline (decision D4): application → review → optional
 * test and interview → decision → offer → acceptance → enrolment.
 *
 * Enrolment is the only door from applicant to student, and it is the same
 * door every student comes through (StudentEnrolmentService), after a
 * duplicate check a person must clear.
 */
class AdmissionService
{
    public function __construct(private StudentEnrolmentService $enrolment)
    {
    }

    /** @param  array<string, mixed>  $data */
    public function create(int $schoolId, array $data, ?User $by, string $source = 'staff'): AdmissionApplication
    {
        $settings = AdmissionSetting::for($schoolId);

        if (! in_array($data['section'], $settings->open_sections ?: array_keys(Sections::SECTIONS), true)) {
            throw new DomainRefusal('Admissions for that section are not open.');
        }

        return DB::transaction(function () use ($schoolId, $data, $by, $source, $settings) {
            $app = AdmissionApplication::withoutGlobalScopes()->create([
                'school_id' => $schoolId,
                'reference' => $this->nextReference($schoolId),
                'section' => $data['section'],
                'session_id' => $data['session_id'] ?? TeacherScope::currentSessionId($schoolId),
                'intended_class_id' => $data['intended_class_id'] ?? null,
                'applicant_name' => trim($data['applicant_name']),
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'gender' => $data['gender'] ?? null,
                'previous_school' => $data['previous_school'] ?? null,
                'previous_class' => $data['previous_class'] ?? null,
                'guardians' => array_values($data['guardians'] ?? []),
                'source' => $source,
                'status' => 'submitted',
            ]);

            foreach ($settings->documentsFor($app->section) as $type) {
                AdmissionDocument::withoutGlobalScopes()->create(['school_id' => $schoolId, 'application_id' => $app->id, 'type' => $type, 'status' => 'pending']);
            }

            $this->event($app, 'created', null, 'submitted', $by, $source === 'online' ? 'Submitted through the school\'s online form.' : null);

            return $app;
        });
    }

    public function transition(AdmissionApplication $app, string $to, ?User $by, string $action, ?string $note = null, array $extra = []): AdmissionApplication
    {
        $from = $app->status;
        $app->update(['status' => $to] + $extra);
        $this->event($app, $action, $from, $to, $by, $note);

        return $app->fresh();
    }

    /**
     * offer | reject | waitlist. An offer names the class and expires.
     */
    public function decide(AdmissionApplication $app, User $by, string $decision, ?string $reason, ?int $classId): AdmissionApplication
    {
        if (! in_array($app->status, ['submitted', 'under_review', 'assessment', 'waitlisted'], true)) {
            throw new DomainRefusal('A decision has already been made on this application.');
        }

        if ($decision === 'offer') {
            $missing = $app->documents()->whereIn('status', ['pending', 'rejected'])->pluck('type')->all();

            if ($missing !== []) {
                throw new DomainRefusal('Required documents are not yet received or verified: '.implode(', ', array_map(fn ($t) => AdmissionSetting::DOCUMENTS[$t] ?? $t, $missing)).'.');
            }

            if (! $classId) {
                throw new DomainRefusal('Choose the class the offer is for.');
            }

            $days = AdmissionSetting::for($app->school_id)->offer_validity_days;

            return $this->transition($app, 'offered', $by, 'offered', $reason, [
                'offered_class_id' => $classId, 'offer_expires_at' => now()->addDays($days)->endOfDay(),
                'decided_by' => $by->id, 'decided_at' => now(), 'decision_reason' => $reason,
            ]);
        }

        if ($decision === 'reject' && trim((string) $reason) === '') {
            throw new DomainRefusal('Record the reason for not admitting.');
        }

        return $this->transition($app, $decision === 'reject' ? 'rejected' : 'waitlisted', $by, $decision, $reason, [
            'decided_by' => $by->id, 'decided_at' => now(), 'decision_reason' => $reason,
        ]);
    }

    public function respond(AdmissionApplication $app, ?User $by, bool $accepted): AdmissionApplication
    {
        if ($app->status !== 'offered') {
            throw new DomainRefusal('There is no open offer on this application.');
        }

        if ($app->offer_expires_at?->isPast()) {
            $this->transition($app, 'expired', $by, 'expired');

            throw new DomainRefusal('This offer has expired.');
        }

        return $this->transition($app, $accepted ? 'accepted' : 'declined', $by, $accepted ? 'accepted' : 'declined', null, ['responded_at' => now()]);
    }

    /**
     * Create the student. A likely duplicate stops here until a person says
     * it is a different child; nothing is ever merged (acceptance check 4).
     *
     * @return array{student: Student, duplicates: array}
     */
    public function enrol(AdmissionApplication $app, User $by, array $placement, bool $confirmedNotDuplicate): array
    {
        if ($app->status !== 'accepted') {
            throw new DomainRefusal('Only an accepted offer can be enrolled.');
        }

        $guardians = $app->guardians ?? [];
        $duplicates = $this->enrolment->possibleDuplicates($app->school_id, [
            'name' => (string) $app->applicant_name,
            'date_of_birth' => $app->date_of_birth?->toDateString(),
            'guardian_phone' => $guardians[0]['phone'] ?? null,
        ]);

        if ($duplicates !== [] && ! $confirmedNotDuplicate) {
            throw new DuplicateApplicant($duplicates);
        }

        return DB::transaction(function () use ($app, $by, $placement, $guardians, $duplicates) {
            [$student] = $this->enrolment->enrol($app->school_id, [
                'name' => $app->applicant_name,
                'gender' => $app->gender ?? 'other',
                'date_of_birth' => $app->date_of_birth?->toDateString(),
                'class_id' => $placement['class_id'] ?? $app->offered_class_id,
                'arm_id' => $placement['arm_id'] ?? null,
                'admission_number' => $placement['admission_number'] ?? null,
                'previous_school' => $app->previous_school,
                'guardians' => array_map(fn ($g) => [
                    'name' => $g['name'], 'phone' => $g['phone'], 'email' => $g['email'] ?? null,
                    'relationship' => in_array($g['relationship'] ?? null, ['father', 'mother', 'guardian', 'parent', 'other'], true) ? $g['relationship'] : 'guardian',
                ], $guardians),
            ], $by, null, null, null, 'student.admitted');

            $this->transition($app, 'enrolled', $by, 'enrolled', $duplicates !== [] ? 'Possible duplicates reviewed and confirmed to be a different child.' : null, [
                'student_id' => $student->id, 'enrolled_at' => now(),
            ]);

            return ['student' => $student, 'duplicates' => $duplicates];
        });
    }

    /**
     * Remove personal details from applications that never became students
     * once the retention period has passed (acceptance check 5). The
     * reference, section and outcome stay, so admission statistics survive.
     */
    public function purgeExpired(?int $schoolId = null): int
    {
        $count = 0;

        AdmissionApplication::withoutGlobalScopes()
            ->when($schoolId, fn ($q) => $q->where('school_id', $schoolId))
            ->whereNull('purged_at')
            ->whereNull('student_id')
            ->get()
            ->groupBy('school_id')
            ->each(function ($apps, $sid) use (&$count) {
                $days = AdmissionSetting::for((int) $sid)->retention_days;

                foreach ($apps as $app) {
                    // Open offers expire first; active applications are kept.
                    if ($app->status === 'offered' && $app->offer_expires_at?->isPast()) {
                        $this->transition($app, 'expired', null, 'expired');
                        $app = $app->fresh();
                    }

                    if (in_array($app->status, AdmissionApplication::ACTIVE, true)) {
                        continue;
                    }

                    $since = $app->responded_at ?? $app->decided_at ?? $app->updated_at;

                    if ($since && $since->lt(now()->subDays($days))) {
                        $app->update([
                            'applicant_name' => null, 'date_of_birth' => null, 'gender' => null,
                            'previous_school' => null, 'previous_class' => null, 'guardians' => [], 'purged_at' => now(),
                        ]);
                        AdmissionDocument::withoutGlobalScopes()->where('application_id', $app->id)->update(['note' => null]);
                        $this->event($app, 'purged', $app->status, $app->status, null, "Personal details removed after {$days} days (retention policy).");
                        $count++;
                    }
                }
            });

        return $count;
    }

    public function event(AdmissionApplication $app, string $action, ?string $from, ?string $to, ?User $by, ?string $note = null): void
    {
        AdmissionEvent::withoutGlobalScopes()->create([
            'school_id' => $app->school_id, 'application_id' => $app->id, 'action' => $action,
            'from_status' => $from, 'to_status' => $to, 'user_id' => $by?->id, 'note' => $note,
        ]);
    }

    private function nextReference(int $schoolId): string
    {
        $n = AdmissionApplication::withoutGlobalScopes()->where('school_id', $schoolId)->count() + 1;

        do {
            $ref = sprintf('ADM-%s-%05d', now()->format('Y'), $n++);
        } while (AdmissionApplication::withoutGlobalScopes()->where('school_id', $schoolId)->where('reference', $ref)->exists());

        return $ref;
    }
}
