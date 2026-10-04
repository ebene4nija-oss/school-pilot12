<?php

namespace App\Services\History;

use App\Models\AuditLog;
use App\Models\Guardian;
use App\Models\PreviousSchoolRecord;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\StudentIdentityLink;
use App\Models\Term;
use App\Models\Transfer;
use App\Models\TransferClearanceItem;
use App\Models\TransferConsent;
use App\Models\TransferEvent;
use App\Models\TransferPackage;
use App\Models\TransferSetting;
use App\Models\User;
use App\Services\Results\AuthorisationFailure;
use App\Services\Sections\HeadResolver;
use App\Support\InAppNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Exceptions\DomainRefusal;

/**
 * Transfers between schools (history instructions §6–§8).
 *
 * A transfer never deletes or re-points a student. Outgoing: consent,
 * clearance, approval, a signed package, then the enrolment closes and the
 * record stays as a read-only archive. Incoming: the other school's records
 * arrive read-only with their provenance, are verified by a person, and a new
 * enrolment is created beside them.
 *
 * Between two SchoolPilot schools, records move by one-time code, direct push
 * or a receiving school's request. No school can search another's students:
 * a code or request that does not match gets the same neutral answer as one
 * that does not exist.
 */
class TransferService
{
    public const NEUTRAL = 'If these details match a transfer, the school will see it. Nothing more can be shown.';

    public function __construct(
        private DocumentService $documents,
        private HeadResolver $heads,
    ) {
    }

    // ==================================================================
    // Outgoing
    // ==================================================================

    /**
     * @param  array<string, mixed>  $data  reason, requested_transfer_date, other_school_name, package_options
     */
    public function startOutgoing(Student $student, User $user, string $requestedByType, array $data): Transfer
    {
        $schoolId = (int) $student->school_id;

        $open = Transfer::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('student_id', $student->id)
            ->where('direction', 'outgoing')
            ->whereIn('status', Transfer::OPEN)
            ->first();

        if ($open) {
            throw new DomainRefusal("This student already has an open transfer ({$open->reference}).");
        }

        if ($student->status !== 'active') {
            throw new DomainRefusal('Only an active student can be transferred out.');
        }

        $settings = TransferSetting::for($schoolId);

        return DB::transaction(function () use ($student, $user, $requestedByType, $data, $schoolId, $settings) {
            $transfer = Transfer::create([
                'school_id' => $schoolId,
                'reference' => $this->nextReference($schoolId),
                'direction' => 'outgoing',
                'status' => 'draft',
                'student_id' => $student->id,
                'enrollment_id' => $student->currentEnrollment?->id,
                'reason' => $data['reason'] ?? null,
                'requested_transfer_date' => $data['requested_transfer_date'] ?? null,
                'other_school_name' => $data['other_school_name'] ?? null,
                'requested_by_type' => $requestedByType,
                'requested_by' => $user->id,
                'partial_term' => $this->midTerm($schoolId),
                'package_options' => $this->packageOptions($data['package_options'] ?? []),
                'steps' => $settings->steps(),
                'current_step' => 0,
            ]);

            foreach ($settings->clearance() as $item) {
                TransferClearanceItem::create(['school_id' => $schoolId, 'transfer_id' => $transfer->id, 'item' => $item, 'status' => 'outstanding']);
            }

            $this->refreshAutomaticClearance($transfer);
            $this->event($transfer, 'created', null, 'draft', $user, null, ['requested_by_type' => $requestedByType]);

            // A request from a parent is already a submission.
            if (in_array($requestedByType, ['parent', 'student'], true)) {
                $this->submit($transfer, $user);
            }

            return $transfer->fresh();
        });
    }

    /** Move forward from draft: to consent, clearance or approval, whichever is first outstanding. */
    public function submit(Transfer $transfer, User $user): Transfer
    {
        $this->assertStatus($transfer, ['draft']);
        $this->transition($transfer, 'submitted', $user, 'submitted');

        return $this->advance($transfer->fresh(), $user);
    }

    /**
     * A guardian's decisions: the transfer itself, then each sensitive
     * category separately. Consent can be refused; a refusal of the transfer
     * consent stops the transfer.
     *
     * @param  array<string, bool>  $decisions  category => given
     */
    public function recordConsent(Transfer $transfer, User $guardianUser, array $decisions, ?string $ip): Transfer
    {
        $student = Student::withoutGlobalScopes()->findOrFail($transfer->student_id);

        if (! $student->isGuardedBy($guardianUser)) {
            throw new AuthorisationFailure('Only a guardian of this child can give or refuse consent.');
        }

        $this->assertStatus($transfer, ['awaiting_consent', 'clearance_pending', 'pending_approval', 'submitted']);

        return DB::transaction(function () use ($transfer, $guardianUser, $decisions, $ip) {
            foreach ($decisions as $category => $given) {
                if (! isset(TransferConsent::CATEGORIES[$category])) {
                    continue;
                }

                TransferConsent::create([
                    'school_id' => $transfer->school_id,
                    'transfer_id' => $transfer->id,
                    'category' => $category,
                    'given' => (bool) $given,
                    'given_by' => $guardianUser->id,
                    'ip_address' => $ip,
                    'decided_at' => now(),
                ]);
            }

            $this->event($transfer, 'consent_recorded', $transfer->status, $transfer->status, $guardianUser, null, $decisions);

            if (array_key_exists('transfer', $decisions) && ! $decisions['transfer']) {
                $this->transition($transfer, 'cancelled', $guardianUser, 'consent_refused', 'The guardian refused consent.');

                return $transfer->fresh();
            }

            return $transfer->status === 'awaiting_consent' ? $this->advance($transfer->fresh(), $guardianUser) : $transfer->fresh();
        });
    }

    /** Clear, or override with a reason, one clearance item. */
    public function updateClearance(Transfer $transfer, User $user, string $item, string $status, ?string $note, ?string $overrideReason): Transfer
    {
        $this->assertStatus($transfer, ['submitted', 'awaiting_consent', 'clearance_pending']);

        if (! in_array($status, ['cleared', 'overridden', 'outstanding'], true)) {
            throw new DomainRefusal('Unknown clearance status.');
        }

        if ($status === 'overridden' && trim((string) $overrideReason) === '') {
            throw new DomainRefusal('An override needs a reason.');
        }

        $row = TransferClearanceItem::withoutGlobalScopes()->where('transfer_id', $transfer->id)->where('item', $item)->firstOrFail();
        $row->update(['status' => $status, 'note' => $note, 'override_reason' => $overrideReason, 'updated_by' => $user->id]);

        $this->event($transfer, 'clearance_updated', $transfer->status, $transfer->status, $user, $overrideReason ?? $note, ['item' => $item, 'status' => $status]);

        return $transfer->status === 'clearance_pending' ? $this->advance($transfer->fresh(), $user) : $transfer->fresh();
    }

    /** Approve the current step of the school's chain. */
    public function approve(Transfer $transfer, User $user, ?string $note): Transfer
    {
        $this->assertStatus($transfer, ['pending_approval']);

        $step = $transfer->steps[$transfer->current_step] ?? null;

        if (! $this->canActOnStep($transfer, $step, $user)) {
            throw new AuthorisationFailure('This approval step belongs to the '.strtolower(TransferSetting::STEP_LABELS[$step] ?? (string) $step).'.');
        }

        return DB::transaction(function () use ($transfer, $user, $note, $step) {
            $this->event($transfer, 'approved_step', 'pending_approval', 'pending_approval', $user, $note, ['step' => $step]);

            if ($transfer->current_step < count($transfer->steps) - 1) {
                $transfer->update(['current_step' => $transfer->current_step + 1]);

                return $transfer->fresh();
            }

            $this->transition($transfer, 'approved', $user, 'approved', $note);
            $this->preparePackage($transfer->fresh(), $user);

            return $transfer->fresh();
        });
    }

    public function reject(Transfer $transfer, User $user, string $reason): Transfer
    {
        if (! $transfer->isOpen()) {
            throw new DomainRefusal('This transfer is already closed.');
        }

        $this->transition($transfer, 'rejected', $user, 'rejected', $reason);
        $this->notifyGuardians($transfer, 'Transfer request rejected', "Transfer {$transfer->reference} was not approved. Contact the school for details.");

        return $transfer->fresh();
    }

    public function cancel(Transfer $transfer, User $user, string $reason): Transfer
    {
        if (! $transfer->isOpen()) {
            throw new DomainRefusal('This transfer is already closed.');
        }

        $this->transition($transfer, 'cancelled', $user, 'cancelled', $reason);

        return $transfer->fresh();
    }

    /**
     * Issue a one-time code for the parent to give the receiving school
     * (§6.3, the default SchoolPilot route). Shown once; stored hashed.
     */
    public function issueCode(Transfer $transfer, User $user): string
    {
        $this->assertStatus($transfer, ['approved', 'awaiting_acceptance']);
        $this->assertConsent($transfer);

        $code = strtoupper(implode('-', str_split(Str::random(12), 4)));
        $code = preg_replace('/[^A-Z0-9-]/', 'X', $code);

        $transfer->update([
            'transfer_code_hash' => hash('sha256', $code),
            'transfer_code_expires_at' => now()->addDays(TransferSetting::for($transfer->school_id)->code_expiry_days),
        ]);

        if ($transfer->status === 'approved') {
            $this->transition($transfer, 'awaiting_acceptance', $user, 'code_issued');
        } else {
            $this->event($transfer, 'code_reissued', $transfer->status, $transfer->status, $user);
        }

        $this->notifyGuardians($transfer, 'Transfer code ready', "Transfer {$transfer->reference} is approved. The school has a code for you to give your child's new school.");

        return $code;
    }

    /** Send the package straight to another SchoolPilot school. */
    public function push(Transfer $transfer, User $user, School $destination): Transfer
    {
        $this->assertStatus($transfer, ['approved', 'awaiting_acceptance']);
        $this->assertConsent($transfer);

        if ($destination->id === $transfer->school_id || $destination->is_platform) {
            throw new DomainRefusal('Choose another school on SchoolPilot.');
        }

        return DB::transaction(function () use ($transfer, $user, $destination) {
            $incoming = $this->createIncomingFromOutgoing($transfer, $destination->id, 'awaiting_acceptance', 'receiving_school');

            $transfer->update(['other_school_id' => $destination->id, 'other_school_name' => $transfer->other_school_name ?? $destination->name, 'linked_transfer_id' => $incoming->id]);

            if ($transfer->status === 'approved') {
                $this->transition($transfer, 'awaiting_acceptance', $user, 'pushed', null, ['destination_school_id' => $destination->id]);
            }

            return $transfer->fresh();
        });
    }

    /** A time-limited link to the package, for a receiving school off SchoolPilot. */
    public function secureLink(Transfer $transfer, User $user): string
    {
        $this->assertStatus($transfer, ['approved', 'awaiting_acceptance', 'accepted']);
        $this->assertConsent($transfer);

        $package = $this->currentPackage($transfer) ?? throw new DomainRefusal('No current package. Prepare it again.');
        $token = Str::random(48);
        $package->update(['link_token_hash' => hash('sha256', $token)]);

        $this->event($transfer, 'secure_link_issued', $transfer->status, $transfer->status, $user, null, ['expires_at' => $package->expires_at->toIso8601String()]);

        return $token;
    }

    /**
     * Finish: the student leaves, the enrolment closes, everything is kept
     * read-only. Student sign-in ends if the school's policy says so.
     */
    public function complete(Transfer $transfer, User $user): Transfer
    {
        $this->assertStatus($transfer, ['approved', 'awaiting_acceptance', 'accepted', 'records_shared']);

        return DB::transaction(function () use ($transfer, $user) {
            $student = Student::withoutGlobalScopes()->findOrFail($transfer->student_id);
            $date = ($transfer->requested_transfer_date ?? now())->toDateString();

            StudentEnrollment::withoutGlobalScopes()
                ->where('school_id', $transfer->school_id)
                ->where('student_id', $student->id)
                ->where('status', 'active')
                ->update([
                    'status' => 'closed',
                    'outcome' => 'transferred_out',
                    'closed_on' => $date,
                    'destination_school' => $transfer->other_school_name,
                    'outcome_remarks' => "Transfer {$transfer->reference}",
                    'recorded_by' => $user->id,
                ]);

            $student->update(['status' => 'transferred']);

            if (TransferSetting::for($transfer->school_id)->end_student_access_on_completion && $student->user_id) {
                // Sign-in only. Nothing is deleted: the record stays as an archive.
                DB::table('personal_access_tokens')->where('tokenable_type', User::class)->where('tokenable_id', $student->user_id)->delete();
                DB::table('users')->where('id', $student->user_id)->update(['status' => 'suspended']);
            }

            $transfer->update(['completed_at' => now()]);
            $this->transition($transfer, 'completed', $user, 'completed');
            $this->syncLinked($transfer, 'completed', 'Sending school completed the transfer.');
            $this->notifyGuardians($transfer, 'Transfer completed', "Transfer {$transfer->reference} is complete.");

            return $transfer->fresh();
        });
    }

    // ==================================================================
    // Incoming
    // ==================================================================

    /** A transfer-in entered by this school's registrar, from any school. */
    public function startIncoming(int $schoolId, User $user, array $applicant, ?string $fromSchool): Transfer
    {
        return DB::transaction(function () use ($schoolId, $user, $applicant, $fromSchool) {
            $transfer = Transfer::create([
                'school_id' => $schoolId,
                'reference' => $applicant['transfer_reference'] ?? $this->nextReference($schoolId),
                'direction' => 'incoming',
                'status' => 'submitted',
                'other_school_name' => $fromSchool,
                'requested_by_type' => 'registrar',
                'requested_by' => $user->id,
                'applicant' => $applicant,
            ]);

            $this->event($transfer, 'created', null, 'submitted', $user);

            return $transfer;
        });
    }

    /**
     * Redeem a parent's code at the receiving school. Every failure — wrong
     * code, expired, used, surname mismatch — returns null, and the caller
     * gives the same neutral answer for all of them.
     */
    public function redeemCode(int $receivingSchoolId, User $user, string $code, string $surname): ?Transfer
    {
        $hash = hash('sha256', strtoupper(trim($code)));

        $outgoing = Transfer::withoutGlobalScopes()
            ->where('transfer_code_hash', $hash)
            ->where('direction', 'outgoing')
            ->where('transfer_code_expires_at', '>', now())
            ->whereIn('status', ['awaiting_acceptance'])
            ->first();

        if (! $outgoing || $outgoing->school_id === $receivingSchoolId) {
            return null;
        }

        $name = (string) Student::withoutGlobalScopes()->with('user:id,name')->find($outgoing->student_id)?->user?->name;
        $tokens = array_map('mb_strtolower', preg_split('/\s+/', trim($name)) ?: []);

        if (! in_array(mb_strtolower(trim($surname)), $tokens, true)) {
            return null;
        }

        return DB::transaction(function () use ($outgoing, $receivingSchoolId, $user) {
            // One use only.
            $outgoing->update(['transfer_code_hash' => null]);

            $incoming = $this->createIncomingFromOutgoing($outgoing, $receivingSchoolId, 'under_verification', 'receiving_school');
            $this->importPackage($incoming, $outgoing, $user);

            $outgoing->update(['other_school_id' => $receivingSchoolId, 'linked_transfer_id' => $incoming->id]);
            $this->transition($outgoing, 'records_shared', null, 'records_shared', 'Receiving school redeemed the transfer code.', ['receiving_school_id' => $receivingSchoolId]);

            return $incoming->fresh();
        });
    }

    /** The receiving school accepts a pushed transfer, which imports the records. */
    public function acceptPushed(Transfer $incoming, User $user): Transfer
    {
        $this->assertStatus($incoming, ['awaiting_acceptance']);

        $outgoing = Transfer::withoutGlobalScopes()->findOrFail($incoming->linked_transfer_id);

        return DB::transaction(function () use ($incoming, $outgoing, $user) {
            $this->importPackage($incoming, $outgoing, $user);
            $this->transition($incoming, 'under_verification', $user, 'records_received');
            $this->transition($outgoing, 'records_shared', null, 'records_shared', 'The receiving school accepted the records.');

            return $incoming->fresh();
        });
    }

    /**
     * A receiving school asks another SchoolPilot school for a child's
     * records. Whatever happens, the caller answers with NEUTRAL: the asking
     * school learns nothing about whether the child exists there.
     */
    public function requestRecords(int $requestingSchoolId, User $user, School $sendingSchool, array $details): void
    {
        DB::transaction(function () use ($requestingSchoolId, $user, $sendingSchool, $details) {
            // Recorded on the asking side whatever the outcome, so its own
            // list cannot reveal a match either.
            $incoming = Transfer::create([
                'school_id' => $requestingSchoolId,
                'reference' => $this->nextReference($requestingSchoolId),
                'direction' => 'incoming',
                'status' => 'records_requested',
                'other_school_name' => $sendingSchool->name,
                'other_school_id' => $sendingSchool->id,
                'requested_by_type' => 'registrar',
                'requested_by' => $user->id,
                'applicant' => $details,
            ]);
            $this->event($incoming, 'records_requested', null, 'records_requested', $user);

            $student = $this->matchForRequest($sendingSchool->id, $details);

            if (! $student) {
                return;
            }

            $outgoing = Transfer::create([
                'school_id' => $sendingSchool->id,
                'reference' => $this->nextReference($sendingSchool->id),
                'direction' => 'outgoing',
                'status' => 'records_requested',
                'student_id' => $student->id,
                'enrollment_id' => $student->currentEnrollment?->id,
                'other_school_id' => $requestingSchoolId,
                'other_school_name' => School::find($requestingSchoolId)?->name,
                'linked_transfer_id' => $incoming->id,
                'requested_by_type' => 'receiving_school',
                'partial_term' => $this->midTerm($sendingSchool->id),
                'package_options' => $this->packageOptions([]),
                'steps' => TransferSetting::for($sendingSchool->id)->steps(),
            ]);

            foreach (TransferSetting::for($sendingSchool->id)->clearance() as $item) {
                TransferClearanceItem::create(['school_id' => $sendingSchool->id, 'transfer_id' => $outgoing->id, 'item' => $item, 'status' => 'outstanding']);
            }

            $incoming->update(['linked_transfer_id' => $outgoing->id]);
            $this->event($outgoing, 'records_requested', null, 'records_requested', null, 'Requested by another school on SchoolPilot.');
        });
    }

    /** The sending school takes a request into its own workflow. */
    public function takeUpRequest(Transfer $outgoing, User $user): Transfer
    {
        $this->assertStatus($outgoing, ['records_requested']);
        $this->transition($outgoing, 'submitted', $user, 'request_taken_up');

        return $this->advance($outgoing->fresh(), $user);
    }

    /** Mark a received record verified, a discrepancy, or needing more information. */
    public function verifyRecord(PreviousSchoolRecord $record, User $user, string $status, ?string $note): PreviousSchoolRecord
    {
        if (! in_array($status, ['verified', 'discrepancy', 'additional_info_required'], true)) {
            throw new DomainRefusal('Unknown verification status.');
        }

        $provenance = match ($status) {
            'verified' => $record->source_package_hash ? 'imported_verified_electronic' : 'imported_verified_upload',
            'discrepancy' => 'discrepancy',
            default => $record->provenance,
        };

        $record->update(['verification_status' => $status, 'provenance' => $provenance, 'verified_by' => $user->id, 'verified_at' => now()]);

        if ($record->transfer_id) {
            $transfer = Transfer::withoutGlobalScopes()->find($record->transfer_id);
            $this->event($transfer, 'record_'.$status, $transfer->status, $transfer->status, $user, $note);

            if ($status === 'additional_info_required') {
                $this->transition($transfer, 'additional_info_required', $user, 'additional_info_required', $note);
                $this->syncLinked($transfer, 'additional_info_required', $note);
            }
        }

        return $record->fresh();
    }

    /** A local correction to a received record: a new row; the original stays. */
    public function amendRecord(PreviousSchoolRecord $record, User $user, array $data, string $reason): PreviousSchoolRecord
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        return PreviousSchoolRecord::create([
            'school_id' => $record->school_id,
            'student_id' => $record->student_id,
            'transfer_id' => $record->transfer_id,
            'source_school_name' => $record->source_school_name,
            'source_school_id' => $record->source_school_id,
            'provenance' => 'manually_entered',
            'verification_status' => 'verified',
            'data' => $json,
            'data_hash' => hash('sha256', $json),
            'amends_id' => $record->id,
            'amendment_reason' => $reason,
            'imported_by' => $user->id,
            'verified_by' => $user->id,
            'verified_at' => now(),
        ]);
    }

    /**
     * Admit the incoming student: a new enrolment beside the received
     * history, never over it. An existing student is linked only when a
     * person names them (`link_student_id`).
     *
     * @param  array<string, mixed>  $enrolment  name, gender, class_id, arm_id, date_of_birth, guardians…
     */
    public function admit(Transfer $incoming, User $user, array $enrolment, ?int $existingStudentId = null): Student
    {
        $this->assertStatus($incoming, ['under_verification', 'records_shared', 'submitted', 'accepted', 'additional_info_required']);

        $unverified = PreviousSchoolRecord::withoutGlobalScopes()
            ->where('transfer_id', $incoming->id)
            ->whereNull('amends_id')
            ->where('verification_status', 'pending')
            ->count();

        if ($unverified > 0) {
            throw new DomainRefusal("{$unverified} received record(s) still need verifying before admission.");
        }

        return DB::transaction(function () use ($incoming, $user, $enrolment, $existingStudentId) {
            if ($existingStudentId) {
                $student = Student::withoutGlobalScopes()->where('school_id', $incoming->school_id)->findOrFail($existingStudentId);
            } else {
                [$student] = app(\App\Services\StudentEnrolmentService::class)->enrol(
                    $incoming->school_id,
                    $enrolment + ['previous_school' => $incoming->other_school_name],
                    $user,
                    null,
                    null,
                    null,
                    'student.admitted_by_transfer'
                );
            }

            PreviousSchoolRecord::withoutGlobalScopes()->where('transfer_id', $incoming->id)->update(['student_id' => $student->id]);

            $incoming->update(['new_student_id' => $student->id, 'student_id' => $student->id, 'placement_class_id' => $enrolment['class_id'] ?? $student->class_id]);

            // The two schools' records of this child, joined by a person's
            // decision on an approved transfer, never by a name match.
            if ($incoming->linked_transfer_id) {
                $outgoing = Transfer::withoutGlobalScopes()->find($incoming->linked_transfer_id);

                if ($outgoing?->student_id) {
                    StudentIdentityLink::firstOrCreate(
                        ['from_student_id' => $outgoing->student_id, 'to_student_id' => $student->id],
                        ['transfer_id' => $incoming->id, 'matched_on' => ['transfer_reference', 'staff_confirmation'], 'confirmed_by' => $user->id]
                    );
                }
            }

            $this->transition($incoming, 'completed', $user, 'admitted', null, ['student_id' => $student->id]);
            $incoming->update(['completed_at' => now()]);
            $this->syncLinked($incoming, 'accepted', 'The receiving school admitted the student.');

            return $student;
        });
    }

    // ==================================================================
    // Packages
    // ==================================================================

    /**
     * The signed package (§6.2 step 5). Identity and academic records by
     * default; health, special-needs and discipline notes only with the
     * guardian's consent to that category.
     */
    public function preparePackage(Transfer $transfer, User $user): TransferPackage
    {
        $student = Student::withoutGlobalScopes()->with('user:id,name', 'currentClass:id,name')->findOrFail($transfer->student_id);
        $schoolId = (int) $transfer->school_id;
        $options = $transfer->package_options ?? $this->packageOptions([]);
        $consented = $this->consentedCategories($transfer);

        $snapshots = app(AcademicRecordService::class)->currentSnapshots($schoolId, $student->id);

        $payload = [
            'format' => 'schoolpilot-transfer-v1',
            'reference' => $transfer->reference,
            'sending_school' => ['id' => $schoolId, 'name' => School::find($schoolId)?->name],
            'student' => [
                'full_name' => $student->user?->name,
                'admission_number' => $student->admission_number,
                'date_of_birth' => $student->date_of_birth?->toDateString(),
                'gender' => $student->gender,
                'last_class' => $student->currentClass?->name,
            ],
            'partial_term' => $transfer->partial_term,
            'enrolment_history' => DB::table('student_enrollments')
                ->leftJoin('academic_sessions', 'academic_sessions.id', '=', 'student_enrollments.session_id')
                ->leftJoin('classes', 'classes.id', '=', 'student_enrollments.class_id')
                ->where('student_enrollments.school_id', $schoolId)
                ->where('student_enrollments.student_id', $student->id)
                ->get(['academic_sessions.name as session', 'classes.name as class', 'student_enrollments.outcome', 'student_enrollments.enrolled_on', 'student_enrollments.closed_on'])
                ->map(fn ($r) => (array) $r)->all(),
            'terms' => in_array('academic', $options, true) ? $snapshots->map(fn ($s) => [
                'session' => $s->session_name,
                'term' => $s->term_name,
                'class' => $s->class_name,
                'provenance' => $s->provenance,
                'version' => $s->version,
                'figures' => array_diff_key($s->figures(), array_flip(['ratings', 'form_teacher_comment', 'principal_comment', 'broadsheet_reference', 'class_average'])),
                'snapshot_hash' => $s->data_hash,
            ])->values()->all() : [],
            'attendance' => in_array('attendance', $options, true)
                ? $snapshots->map(fn ($s) => ['session' => $s->session_name, 'term' => $s->term_name, 'attendance' => $s->figures()['attendance'] ?? null])->values()->all()
                : [],
            'conduct' => in_array('conduct', $options, true)
                ? $snapshots->map(fn ($s) => ['session' => $s->session_name, 'term' => $s->term_name, 'ratings' => $s->figures()['ratings'] ?? null])->values()->all()
                : [],
        ];

        if (in_array('health', $consented, true)) {
            $payload['health'] = ['blood_group' => $student->blood_group, 'allergies' => $student->allergies, 'medical_notes' => $student->medical_notes];
        }

        if (in_array('discipline', $consented, true)) {
            $payload['discipline'] = DB::table('behavior_reports')->where('school_id', $schoolId)->where('student_id', $student->id)->get(['incident_date', 'type', 'title', 'description'])->map(fn ($r) => (array) $r)->all();
        }

        $payload['generated_at'] = now()->toIso8601String();
        $expires = now()->addDays(TransferSetting::for($schoolId)->package_expiry_days);
        $payload['expires_at'] = $expires->toIso8601String();

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        $hash = hash('sha256', $json);

        return DB::transaction(function () use ($transfer, $user, $json, $hash, $expires, $student) {
            TransferPackage::withoutGlobalScopes()->where('transfer_id', $transfer->id)->whereNull('superseded_at')
                ->update(['superseded_at' => now(), 'superseded_reason' => 'Replaced by a newer package.']);

            $package = TransferPackage::create([
                'school_id' => $transfer->school_id,
                'transfer_id' => $transfer->id,
                'payload' => $json,
                'payload_hash' => $hash,
                'signature' => $this->documents->sign($hash),
                'expires_at' => $expires,
                'created_by' => $user->id,
            ]);

            // The documents that travel with it.
            foreach (['transcript', 'transfer_certificate'] as $type) {
                $this->documents->issue($type, $student, $user, $transfer);
            }

            $this->event($transfer, 'package_prepared', $transfer->status, $transfer->status, $user, null, ['hash' => $hash]);

            return $package;
        });
    }

    public function currentPackage(Transfer $transfer): ?TransferPackage
    {
        return TransferPackage::withoutGlobalScopes()
            ->where('transfer_id', $transfer->id)
            ->whereNull('superseded_at')
            ->orderByDesc('id')
            ->first();
    }

    /** Whether a package's signature and hash are genuine. */
    public function packageValid(TransferPackage $package): bool
    {
        return hash_equals($package->payload_hash, hash('sha256', $package->payload))
            && hash_equals($package->signature, $this->documents->sign($package->payload_hash));
    }

    /** A corrected result makes any package already prepared out of date (§8). */
    public function supersedePackagesFor(int $schoolId, int $studentId, string $reason): void
    {
        $transferIds = Transfer::withoutGlobalScopes()->where('school_id', $schoolId)->where('student_id', $studentId)->where('direction', 'outgoing')->pluck('id');

        $affected = TransferPackage::withoutGlobalScopes()->whereIn('transfer_id', $transferIds)->whereNull('superseded_at')->get();

        foreach ($affected as $package) {
            $package->update(['superseded_at' => now(), 'superseded_reason' => $reason]);
            $transfer = Transfer::withoutGlobalScopes()->find($package->transfer_id);
            $this->event($transfer, 'package_superseded', $transfer->status, $transfer->status, null, $reason);

            if ($transfer->linked_transfer_id && ($incoming = Transfer::withoutGlobalScopes()->find($transfer->linked_transfer_id))) {
                $this->event($incoming, 'source_corrected', $incoming->status, $incoming->status, null, 'The sending school corrected a result after sharing. Ask for an updated package.');
                $this->notifySchoolAdmins($incoming->school_id, 'Transferred record corrected', "The sending school corrected a result in transfer {$incoming->reference}.");
            }
        }
    }

    // ==================================================================
    // Internals
    // ==================================================================

    /** From submitted: consent → clearance → approval, stopping at the first that is outstanding. */
    private function advance(Transfer $transfer, ?User $user): Transfer
    {
        $settings = TransferSetting::for($transfer->school_id);

        if ($settings->lawful_basis === 'guardian_consent' && ! $this->hasTransferConsent($transfer)) {
            if ($transfer->status !== 'awaiting_consent') {
                $this->transition($transfer, 'awaiting_consent', $user, 'awaiting_consent');
                $this->notifyGuardians($transfer, 'Consent needed for a transfer', "Transfer {$transfer->reference} needs your consent. Sign in to SchoolPilot to respond.");
            }

            return $transfer->fresh();
        }

        $this->refreshAutomaticClearance($transfer);

        $blocking = TransferClearanceItem::withoutGlobalScopes()
            ->where('transfer_id', $transfer->id)
            ->where('status', 'outstanding')
            ->get()
            // Unpaid fees block release only when the school says so.
            ->filter(fn ($i) => $i->item !== 'fees' || $settings->fees_block_release);

        if ($blocking->isNotEmpty()) {
            if ($transfer->status !== 'clearance_pending') {
                $this->transition($transfer, 'clearance_pending', $user, 'clearance_pending');
            }

            return $transfer->fresh();
        }

        $this->transition($transfer, 'pending_approval', $user, 'pending_approval');
        $transfer->update(['current_step' => 0]);

        return $transfer->fresh();
    }

    /** Fees and library are checked from the records; the rest are ticked by staff. */
    private function refreshAutomaticClearance(Transfer $transfer): void
    {
        $student = Student::withoutGlobalScopes()->find($transfer->student_id);

        if (! $student) {
            return;
        }

        $owing = DB::table('invoices')->where('school_id', $transfer->school_id)->where('student_id', $student->id)
            ->whereRaw('total_amount > amount_paid')->exists();

        $borrowed = $student->user_id && DB::getSchemaBuilder()->hasTable('borrow_records')
            && DB::table('borrow_records')->where('user_id', $student->user_id)->whereNull('returned_at')->exists();

        foreach (['fees' => $owing, 'library' => $borrowed] as $item => $outstanding) {
            TransferClearanceItem::withoutGlobalScopes()
                ->where('transfer_id', $transfer->id)
                ->where('item', $item)
                ->where('status', '!=', 'overridden')
                ->update(['status' => $outstanding ? 'outstanding' : 'cleared', 'note' => $outstanding ? 'Found outstanding in the records.' : 'Nothing outstanding in the records.']);
        }
    }

    private function canActOnStep(Transfer $transfer, ?string $step, User $user): bool
    {
        $role = $user->userProfile?->role;

        if ($step === 'principal') {
            $principals = DB::table('section_head_assignments')
                ->where('school_id', $transfer->school_id)->where('position', 'principal')->whereNull('ended_at')
                ->pluck('user_id')->map(fn ($id) => (int) $id)->all();

            return in_array($user->id, $principals, true) || ($principals === [] && $role === 'school_admin');
        }

        return $role === 'school_admin';
    }

    private function hasTransferConsent(Transfer $transfer): bool
    {
        $latest = TransferConsent::withoutGlobalScopes()->where('transfer_id', $transfer->id)->where('category', 'transfer')->orderByDesc('id')->first();

        return $latest?->given === true;
    }

    private function assertConsent(Transfer $transfer): void
    {
        if (TransferSetting::for($transfer->school_id)->lawful_basis === 'guardian_consent' && ! $this->hasTransferConsent($transfer)) {
            throw new DomainRefusal('Records cannot be shared until a guardian consents to the transfer.');
        }
    }

    /** @return array<int, string> sensitive categories with a current "yes" */
    private function consentedCategories(Transfer $transfer): array
    {
        return TransferConsent::withoutGlobalScopes()
            ->where('transfer_id', $transfer->id)
            ->orderBy('id')
            ->get()
            ->groupBy('category')
            ->filter(fn ($rows) => $rows->last()->given)
            ->keys()
            ->all();
    }

    private function packageOptions(array $requested): array
    {
        $allowed = ['academic', 'attendance', 'conduct'];
        $chosen = array_values(array_intersect($requested, $allowed));

        return $chosen !== [] ? $chosen : ['academic', 'attendance'];
    }

    private function createIncomingFromOutgoing(Transfer $outgoing, int $receivingSchoolId, string $status, string $by): Transfer
    {
        $student = Student::withoutGlobalScopes()->with('user:id,name')->find($outgoing->student_id);

        $incoming = Transfer::withoutGlobalScopes()->create([
            'school_id' => $receivingSchoolId,
            'reference' => $outgoing->reference,
            'direction' => 'incoming',
            'status' => $status,
            'other_school_name' => School::find($outgoing->school_id)?->name,
            'other_school_id' => $outgoing->school_id,
            'linked_transfer_id' => $outgoing->id,
            'requested_by_type' => $by,
            'partial_term' => $outgoing->partial_term,
            'applicant' => [
                'name' => $student?->user?->name,
                'previous_admission_number' => $student?->admission_number,
                'date_of_birth' => $student?->date_of_birth?->toDateString(),
                'gender' => $student?->gender,
            ],
        ]);

        $this->event($incoming, 'received', null, $status, null, 'From '.$incoming->other_school_name.' on SchoolPilot.');
        $this->notifySchoolAdmins($receivingSchoolId, 'Transfer received', "Transfer {$incoming->reference} from {$incoming->other_school_name} is waiting for you.");

        return $incoming;
    }

    /** Read-only import of the sending school's package. */
    private function importPackage(Transfer $incoming, Transfer $outgoing, User $user): void
    {
        $package = $this->currentPackage($outgoing);

        if (! $package || ! $package->usable() || ! $this->packageValid($package)) {
            throw new DomainRefusal('The sending school\'s package has expired or been replaced. Ask them to prepare it again.');
        }

        $package->increment('access_count');

        PreviousSchoolRecord::create([
            'school_id' => $incoming->school_id,
            'transfer_id' => $incoming->id,
            'source_school_name' => $incoming->other_school_name,
            'source_school_id' => $outgoing->school_id,
            'source_package_hash' => $package->payload_hash,
            'provenance' => 'imported_verified_electronic',
            'verification_status' => 'pending',
            'data' => $package->payload,
            'data_hash' => $package->payload_hash,
            'imported_by' => $user->id,
        ]);

        AuditLog::create([
            'school_id' => $incoming->school_id,
            'user_id' => $user->id,
            'action' => 'transfer.package_imported',
            'auditable_type' => Transfer::class,
            'auditable_id' => $incoming->id,
            'new_values' => ['package_hash' => $package->payload_hash, 'from_school_id' => $outgoing->school_id],
        ]);
    }

    /** A student at the sending school matching a request, on two identifiers at least. */
    private function matchForRequest(int $schoolId, array $details): ?Student
    {
        $query = Student::withoutGlobalScopes()->with('user:id,name')->where('school_id', $schoolId)->where('status', 'active');

        if (filled($details['transfer_reference'] ?? null)) {
            $ids = Transfer::withoutGlobalScopes()->where('school_id', $schoolId)->where('reference', $details['transfer_reference'])->pluck('student_id');
            $candidate = $query->clone()->whereIn('id', $ids)->first();
        } elseif (filled($details['previous_admission_number'] ?? null) && filled($details['date_of_birth'] ?? null)) {
            $candidate = $query->clone()->where('admission_number', $details['previous_admission_number'])->get()
                ->first(fn ($s) => $s->date_of_birth?->toDateString() === substr((string) $details['date_of_birth'], 0, 10));
        } else {
            return null;
        }

        if (! $candidate) {
            return null;
        }

        $surname = mb_strtolower(trim((string) ($details['surname'] ?? '')));
        $tokens = array_map('mb_strtolower', preg_split('/\s+/', (string) $candidate->user?->name) ?: []);

        return $surname !== '' && in_array($surname, $tokens, true) ? $candidate : null;
    }

    private function syncLinked(Transfer $transfer, string $status, ?string $note): void
    {
        if (! $transfer->linked_transfer_id) {
            return;
        }

        $other = Transfer::withoutGlobalScopes()->find($transfer->linked_transfer_id);

        if ($other && $other->isOpen()) {
            $this->transition($other, $status, null, 'synced_from_other_school', $note);
        }
    }

    private function midTerm(int $schoolId): bool
    {
        $today = now()->toDateString();

        return Term::withoutGlobalScopes()->where('school_id', $schoolId)
            ->where('start_date', '<', $today)->where('end_date', '>', $today.' 23:59:59')->exists();
    }

    private function assertStatus(Transfer $transfer, array $allowed): void
    {
        if (! in_array($transfer->status, $allowed, true)) {
            throw new DomainRefusal('This transfer is '.strtolower(Transfer::STATUSES[$transfer->status] ?? $transfer->status).'; that action is not available now.');
        }
    }

    private function transition(Transfer $transfer, string $to, ?User $user, string $action, ?string $note = null, ?array $detail = null): void
    {
        $from = $transfer->status;
        $transfer->update(['status' => $to]);
        $this->event($transfer, $action, $from, $to, $user, $note, $detail);
    }

    private function event(Transfer $transfer, string $action, ?string $from, ?string $to, ?User $user, ?string $note = null, ?array $detail = null): void
    {
        TransferEvent::withoutGlobalScopes()->create([
            'school_id' => $transfer->school_id,
            'transfer_id' => $transfer->id,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'user_id' => $user?->id,
            'note' => $note,
            'detail' => $detail,
        ]);
    }

    /** Links and statuses only, never academic content (§10.3). */
    private function notifyGuardians(Transfer $transfer, string $title, string $body): void
    {
        if (! $transfer->student_id) {
            return;
        }

        $userIds = Guardian::withoutGlobalScopes()
            ->whereHas('students', fn ($q) => $q->where('students.id', $transfer->student_id))
            ->pluck('user_id')->filter()->all();

        $this->notifyUsers($transfer->school_id, $userIds, $title, $body);
    }

    private function notifySchoolAdmins(int $schoolId, string $title, string $body): void
    {
        $ids = DB::table('user_profiles')->where('school_id', $schoolId)->where('role', 'school_admin')->pluck('user_id')->all();
        $this->notifyUsers($schoolId, $ids, $title, $body);
    }

    private function notifyUsers(int $schoolId, array $userIds, string $title, string $body): void
    {
        InAppNotifier::send($schoolId, array_map('intval', $userIds), 'transfer', $title, $body);
    }

    private function nextReference(int $schoolId): string
    {
        do {
            $ref = sprintf('TRF-%s-%06d', now()->format('Y'), random_int(1, 999999));
        } while (Transfer::withoutGlobalScopes()->where('reference', $ref)->exists());

        return $ref;
    }
}
