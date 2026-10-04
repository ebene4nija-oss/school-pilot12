<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AcademicDocument;
use App\Models\AuditLog;
use App\Models\PreviousSchoolRecord;
use App\Models\School;
use App\Models\Student;
use App\Models\Transfer;
use App\Models\TransferConsent;
use App\Models\TransferPackage;
use App\Models\TransferSetting;
use App\Models\User;
use App\Services\History\TransferService;
use App\Services\Results\AuthorisationFailure;
use App\Services\StudentEnrolmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Exceptions\DomainRefusal;

/**
 * Transfers in and out (TASK: Student Academic History and School Transfer
 * §6–§8). The school admin acts as registrar; the principal step resolves to
 * the school's principal where one is named.
 */
class TransferController extends Controller
{
    public function __construct(private TransferService $transfers)
    {
    }

    // ==================================================================
    // Dashboards
    // ==================================================================

    public function index(Request $request): JsonResponse
    {
        $request->validate(['direction' => 'nullable|in:outgoing,incoming', 'status' => 'nullable|string']);
        $schoolId = $this->schoolId();

        $rows = Transfer::where('school_id', $schoolId)
            ->when($request->filled('direction'), fn ($q) => $q->where('direction', $request->input('direction')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->with('student.user:id,name', 'student.currentClass:id,name')
            ->orderByDesc('id')
            ->paginate(25);

        $rows->getCollection()->transform(fn (Transfer $t) => $this->summary($t));

        $counts = Transfer::where('school_id', $schoolId)
            ->selectRaw('direction, status, COUNT(*) as n')
            ->groupBy('direction', 'status')
            ->get()
            ->groupBy('direction')
            ->map(fn ($g) => $g->pluck('n', 'status'));

        return response()->json(['data' => $rows->items(), 'meta' => ['total' => $rows->total(), 'last_page' => $rows->lastPage()], 'counts' => $counts, 'statuses' => Transfer::STATUSES]);
    }

    public function show(int $id): JsonResponse
    {
        $transfer = $this->find($id)->load('student.user:id,name', 'student.currentClass:id,name');

        return response()->json($this->summary($transfer) + [
            'reason' => $transfer->reason,
            'applicant' => $transfer->applicant,
            'package_options' => $transfer->package_options,
            'steps' => collect($transfer->steps ?? [])->map(fn ($s, $i) => [
                'role' => $s,
                'label' => TransferSetting::STEP_LABELS[$s] ?? $s,
                'state' => $transfer->status === 'pending_approval' ? ($i < $transfer->current_step ? 'done' : ($i === $transfer->current_step ? 'current' : 'waiting')) : (in_array($transfer->status, ['approved', 'awaiting_acceptance', 'records_shared', 'accepted', 'completed'], true) ? 'done' : 'waiting'),
            ]),
            'consents' => $transfer->consents()->orderBy('id')->get(['category', 'given', 'decided_at']),
            'clearance' => $transfer->clearanceItems()->get(['item', 'status', 'note', 'override_reason']),
            'events' => $transfer->events()->with('user:id,name')->get()->map(fn ($e) => [
                'action' => $e->action, 'from' => $e->from_status, 'to' => $e->to_status, 'note' => $e->note,
                'by' => $e->user?->name, 'at' => $e->created_at?->toIso8601String(),
            ]),
            'package' => ($p = $this->transfers->currentPackage($transfer)) ? [
                'hash' => $p->payload_hash, 'expires_at' => $p->expires_at->toIso8601String(), 'usable' => $p->usable(), 'access_count' => $p->access_count,
            ] : null,
            'superseded_packages' => TransferPackage::where('transfer_id', $transfer->id)->whereNotNull('superseded_at')->get(['superseded_at', 'superseded_reason']),
            'documents' => AcademicDocument::where('transfer_id', $transfer->id)->get(['id', 'type', 'reference', 'issued_at', 'revoked_at']),
            'received_records' => PreviousSchoolRecord::where('transfer_id', $transfer->id)->orderBy('id')->get()
                ->map(fn ($r) => ['id' => $r->id, 'school' => $r->source_school_name, 'provenance' => $r->provenance, 'verification_status' => $r->verification_status, 'amends_id' => $r->amends_id, 'amendment_reason' => $r->amendment_reason, 'records' => $r->content()]),
            'code_active' => $transfer->transfer_code_hash !== null && $transfer->transfer_code_expires_at?->isFuture(),
        ]);
    }

    public function settings(): JsonResponse
    {
        $settings = TransferSetting::for($this->schoolId());

        return response()->json($settings->toArray() + ['approval_steps' => $settings->steps(), 'clearance_items' => $settings->clearance(), 'step_labels' => TransferSetting::STEP_LABELS]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'approval_steps' => 'sometimes|array|min:1|max:3',
            'approval_steps.*' => ['string', Rule::in(array_keys(TransferSetting::STEP_LABELS))],
            'fees_block_release' => 'sometimes|boolean',
            'package_expiry_days' => 'sometimes|integer|min:1|max:90',
            'code_expiry_days' => 'sometimes|integer|min:1|max:90',
            'lawful_basis' => 'sometimes|in:guardian_consent,legal_obligation,contract',
            'students_may_request' => 'sometimes|boolean',
            'end_student_access_on_completion' => 'sometimes|boolean',
            'clearance_items' => 'sometimes|array|max:10',
            'clearance_items.*' => 'string|max:40',
            'applicant_retention_days' => 'sometimes|integer|min:30|max:3650',
        ]);

        $settings = TransferSetting::withoutGlobalScopes()->firstOrNew(['school_id' => $this->schoolId()]);
        $old = $settings->exists ? $settings->toArray() : null;
        $settings->fill($data)->save();

        AuditLog::create(['school_id' => $this->schoolId(), 'user_id' => $request->user()->id, 'action' => 'transfer.settings_updated', 'auditable_type' => TransferSetting::class, 'auditable_id' => $settings->id, 'old_values' => $old, 'new_values' => $settings->toArray()]);

        return $this->settings();
    }

    // ==================================================================
    // Outgoing
    // ==================================================================

    public function startOutgoing(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $data = $request->validate([
            'student_id' => ['required', Rule::exists('students', 'id')->where('school_id', $schoolId)],
            'reason' => 'nullable|string|max:1000',
            'requested_transfer_date' => 'nullable|date',
            'other_school_name' => 'nullable|string|max:255',
            'package_options' => 'nullable|array',
            'package_options.*' => 'in:academic,attendance,conduct',
            'submit' => 'boolean',
        ]);

        return $this->attempt(function () use ($request, $data) {
            $student = Student::with('currentEnrollment')->findOrFail($data['student_id']);
            $transfer = $this->transfers->startOutgoing($student, $request->user(), 'registrar', $data);

            if ($request->boolean('submit')) {
                $transfer = $this->transfers->submit($transfer, $request->user());
            }

            return ['message' => "Transfer {$transfer->reference} started.", 'transfer' => $this->summary($transfer)];
        }, 201);
    }

    public function submit(Request $request, int $id): JsonResponse
    {
        return $this->attempt(fn () => ['transfer' => $this->summary($this->transfers->submit($this->find($id), $request->user()))]);
    }

    public function clearance(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'item' => 'required|string|max:40',
            'status' => 'required|in:cleared,overridden,outstanding',
            'note' => 'nullable|string|max:500',
            'override_reason' => 'nullable|string|max:500',
        ]);

        return $this->attempt(fn () => ['transfer' => $this->summary($this->transfers->updateClearance($this->find($id), $request->user(), $data['item'], $data['status'], $data['note'] ?? null, $data['override_reason'] ?? null))]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $request->validate(['note' => 'nullable|string|max:1000']);

        return $this->attempt(fn () => ['transfer' => $this->summary($this->transfers->approve($this->find($id), $request->user(), $request->input('note')))]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $request->validate(['reason' => 'required|string|min:3|max:1000']);

        return $this->attempt(fn () => ['transfer' => $this->summary($this->transfers->reject($this->find($id), $request->user(), $request->input('reason')))]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $request->validate(['reason' => 'required|string|min:3|max:1000']);

        return $this->attempt(fn () => ['transfer' => $this->summary($this->transfers->cancel($this->find($id), $request->user(), $request->input('reason')))]);
    }

    /** The code is returned once and never stored in readable form. */
    public function issueCode(Request $request, int $id): JsonResponse
    {
        return $this->attempt(fn () => [
            'code' => $this->transfers->issueCode($this->find($id), $request->user()),
            'message' => 'Give this code to the parent for the new school. It works once and expires; it will not be shown again.',
        ]);
    }

    public function push(Request $request, int $id): JsonResponse
    {
        $request->validate(['school_subdomain' => 'required|string|max:100']);

        return $this->attempt(function () use ($request, $id) {
            $destination = School::where('subdomain', strtolower(trim($request->input('school_subdomain'))))->first()
                ?? throw new DomainRefusal('No SchoolPilot school has that address.');

            return ['message' => "Sent to {$destination->name}. They must accept it.", 'transfer' => $this->summary($this->transfers->push($this->find($id), $request->user(), $destination))];
        });
    }

    public function secureLink(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $token = $this->transfers->secureLink($this->find($id), $request->user());

            return [
                'url' => rtrim((string) config('app.url'), '/')."/api/v1/transfer-packages/{$token}",
                'message' => 'Send this link only to the receiving school. It expires with the package and is shown once.',
            ];
        });
    }

    public function preparePackage(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $transfer = $this->find($id);

            if (! in_array($transfer->status, ['approved', 'awaiting_acceptance', 'records_shared', 'accepted'], true)) {
                throw new DomainRefusal('A package is prepared once the transfer is approved.');
            }

            $package = $this->transfers->preparePackage($transfer, $request->user());

            return ['message' => 'A fresh package was prepared; the previous one is superseded.', 'expires_at' => $package->expires_at->toIso8601String()];
        });
    }

    /** Manual export: the signed package as a file, for a school not on SchoolPilot. */
    public function downloadPackage(Request $request, int $id)
    {
        $transfer = $this->find($id);
        $package = $this->transfers->currentPackage($transfer);

        if (! $package || ! $package->usable()) {
            return response()->json(['message' => 'No current package. Prepare it again.'], 409);
        }

        $package->increment('access_count');
        AuditLog::create(['school_id' => $transfer->school_id, 'user_id' => $request->user()->id, 'action' => 'transfer.package_downloaded', 'auditable_type' => Transfer::class, 'auditable_id' => $transfer->id]);

        $body = json_encode(['package' => json_decode($package->payload, true), 'hash' => $package->payload_hash, 'signature' => $package->signature], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return response($body, 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => "attachment; filename=\"{$transfer->reference}.json\"",
            'Cache-Control' => 'no-store',
        ]);
    }

    public function complete(Request $request, int $id): JsonResponse
    {
        return $this->attempt(fn () => ['message' => 'Transfer completed. The record stays here as a read-only archive.', 'transfer' => $this->summary($this->transfers->complete($this->find($id), $request->user()))]);
    }

    public function takeUp(Request $request, int $id): JsonResponse
    {
        return $this->attempt(fn () => ['transfer' => $this->summary($this->transfers->takeUpRequest($this->find($id), $request->user()))]);
    }

    // ==================================================================
    // Incoming
    // ==================================================================

    public function startIncoming(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'date_of_birth' => 'nullable|date|before:today',
            'gender' => 'nullable|in:male,female,other',
            'previous_school' => 'required|string|max:255',
            'previous_admission_number' => 'nullable|string|max:50',
            'previous_class' => 'nullable|string|max:100',
            'intended_class_id' => ['nullable', Rule::exists('classes', 'id')->where('school_id', $this->schoolId())],
            'intended_admission_date' => 'nullable|date',
            'transfer_reference' => 'nullable|string|max:30',
            'guardians' => 'nullable|array|max:2',
            'guardians.*.name' => 'required|string|max:255',
            'guardians.*.phone' => 'required|string|max:20',
            'guardians.*.email' => 'nullable|email',
            'guardians.*.relationship' => 'nullable|string|max:30',
        ]);

        $transfer = $this->transfers->startIncoming($this->schoolId(), $request->user(), $data, $data['previous_school']);

        return response()->json([
            'transfer' => $this->summary($transfer),
            // Checked before anything else, so nobody gets two profiles (§7.1).
            'possible_duplicates' => app(StudentEnrolmentService::class)->possibleDuplicates($this->schoolId(), [
                'name' => $data['name'],
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'guardian_phone' => $data['guardians'][0]['phone'] ?? null,
            ]),
        ], 201);
    }

    public function redeem(Request $request): JsonResponse
    {
        $request->validate(['code' => 'required|string|max:30', 'surname' => 'required|string|max:100']);

        $incoming = $this->transfers->redeemCode($this->schoolId(), $request->user(), $request->input('code'), $request->input('surname'));

        // One answer for every kind of failure.
        if (! $incoming) {
            return response()->json(['message' => 'No transfer matches that code and surname. Check both with the parent.'], 404);
        }

        return response()->json(['message' => 'Records received. Verify them before admitting the student.', 'transfer' => $this->summary($incoming)], 201);
    }

    public function requestRecords(Request $request): JsonResponse
    {
        $data = $request->validate([
            'school_subdomain' => 'required|string|max:100',
            'surname' => 'required|string|max:100',
            'date_of_birth' => 'nullable|date',
            'previous_admission_number' => 'nullable|string|max:50',
            'transfer_reference' => 'nullable|string|max:30',
        ]);

        $sending = School::where('subdomain', strtolower(trim($data['school_subdomain'])))->where('is_platform', false)->first();

        if ($sending && $sending->id !== $this->schoolId()) {
            $this->transfers->requestRecords($this->schoolId(), $request->user(), $sending, $data);
        }

        // Neutral whatever happened: no probing for students across schools.
        return response()->json(['message' => TransferService::NEUTRAL], 202);
    }

    public function acceptPushed(Request $request, int $id): JsonResponse
    {
        return $this->attempt(fn () => ['transfer' => $this->summary($this->transfers->acceptPushed($this->find($id), $request->user()))]);
    }

    /**
     * Records received outside SchoolPilot: a signed package file (verified),
     * or details typed from a paper transcript (unverified until checked).
     */
    public function addRecords(Request $request, int $id): JsonResponse
    {
        $transfer = $this->find($id);
        abort_unless($transfer->direction === 'incoming', 404);

        $request->validate([
            'package' => 'nullable|file|mimes:json,txt|max:4096',
            'records' => 'nullable|array',
            'note' => 'nullable|string|max:500',
        ]);

        if ($request->hasFile('package')) {
            $file = json_decode((string) file_get_contents($request->file('package')->getRealPath()), true);
            $payload = json_encode($file['package'] ?? null, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            $hash = hash('sha256', (string) $payload);
            $genuine = isset($file['hash'], $file['signature']) && hash_equals((string) $file['hash'], $hash)
                && hash_equals((string) $file['signature'], app(\App\Services\History\DocumentService::class)->sign($hash));

            $record = PreviousSchoolRecord::create([
                'school_id' => $transfer->school_id,
                'transfer_id' => $transfer->id,
                'source_school_name' => $file['package']['sending_school']['name'] ?? $transfer->other_school_name ?? 'Previous school',
                'source_package_hash' => $genuine ? $hash : null,
                'provenance' => $genuine ? 'imported_verified_electronic' : 'uploaded_unverified',
                'verification_status' => 'pending',
                'data' => (string) $payload,
                'data_hash' => $hash,
                'imported_by' => $request->user()->id,
            ]);

            $message = $genuine ? 'Signed package received; its signature is genuine. Review it, then mark it verified.' : 'File received, but its signature does not check out. It is held as unverified.';
        } elseif ($request->filled('records')) {
            $json = json_encode($request->input('records'), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

            $record = PreviousSchoolRecord::create([
                'school_id' => $transfer->school_id,
                'transfer_id' => $transfer->id,
                'source_school_name' => $transfer->other_school_name ?? 'Previous school',
                'provenance' => 'uploaded_unverified',
                'verification_status' => 'pending',
                'data' => $json,
                'data_hash' => hash('sha256', $json),
                'imported_by' => $request->user()->id,
            ]);

            $message = 'Records entered. They stay unverified until checked against the documents.';
        } else {
            return response()->json(['message' => 'Attach the package file or enter the records.'], 422);
        }

        if (in_array($transfer->status, ['submitted', 'records_requested'], true)) {
            $transfer->update(['status' => 'under_verification']);
        }

        return response()->json(['message' => $message, 'record_id' => $record->id], 201);
    }

    public function verifyRecord(Request $request, int $recordId): JsonResponse
    {
        $request->validate(['status' => 'required|in:verified,discrepancy,additional_info_required', 'note' => 'nullable|string|max:1000']);
        $record = PreviousSchoolRecord::where('school_id', $this->schoolId())->findOrFail($recordId);

        return $this->attempt(fn () => ['record' => $this->transfers->verifyRecord($record, $request->user(), $request->input('status'), $request->input('note'))->only(['id', 'provenance', 'verification_status'])]);
    }

    public function amendRecord(Request $request, int $recordId): JsonResponse
    {
        $request->validate(['records' => 'required|array', 'reason' => 'required|string|min:5|max:1000']);
        $record = PreviousSchoolRecord::where('school_id', $this->schoolId())->findOrFail($recordId);

        $amended = $this->transfers->amendRecord($record, $request->user(), $request->input('records'), $request->input('reason'));

        return response()->json(['message' => 'Amendment recorded beside the original, which is unchanged.', 'record_id' => $amended->id], 201);
    }

    public function duplicates(int $id): JsonResponse
    {
        $transfer = $this->find($id);
        $a = $transfer->applicant ?? [];

        return response()->json(['data' => app(StudentEnrolmentService::class)->possibleDuplicates($this->schoolId(), [
            'name' => $a['name'] ?? '',
            'date_of_birth' => $a['date_of_birth'] ?? null,
            'guardian_phone' => $a['guardians'][0]['phone'] ?? null,
        ])]);
    }

    public function admit(Request $request, int $id): JsonResponse
    {
        $schoolId = $this->schoolId();
        $data = $request->validate([
            'link_student_id' => ['nullable', Rule::exists('students', 'id')->where('school_id', $schoolId)],
            'name' => 'required_without:link_student_id|string|max:255',
            'gender' => 'required_without:link_student_id|in:male,female,other',
            'date_of_birth' => 'nullable|date|before:today',
            'class_id' => ['required', Rule::exists('classes', 'id')->where('school_id', $schoolId)],
            'arm_id' => ['nullable', Rule::exists('arms', 'id')->where('school_id', $schoolId)],
            'admission_number' => ['nullable', 'string', 'max:50', Rule::unique('students', 'admission_number')->where('school_id', $schoolId)],
            'guardians' => 'nullable|array|max:2',
            'guardians.*.name' => 'required|string|max:255',
            'guardians.*.phone' => 'required|string|max:20',
            'guardians.*.email' => 'nullable|email',
            'guardians.*.relationship' => 'nullable|string|in:father,mother,guardian,parent,other',
        ]);

        return $this->attempt(function () use ($request, $id, $data) {
            $student = $this->transfers->admit($this->find($id), $request->user(), $data, $data['link_student_id'] ?? null);

            return ['message' => "Admitted as {$student->admission_number}. The previous school's records are kept beside the new enrolment.", 'student_id' => $student->id];
        }, 201);
    }

    // ==================================================================
    // Parents
    // ==================================================================

    public function parentIndex(Request $request): JsonResponse
    {
        $childIds = Student::whereHas('guardians', fn ($q) => $q->where('guardians.user_id', $request->user()->id))->pluck('id');

        return response()->json([
            'data' => Transfer::whereIn('student_id', $childIds)->where('direction', 'outgoing')->orderByDesc('id')->get()
                ->map(fn ($t) => [
                    'id' => $t->id,
                    'reference' => $t->reference,
                    'status' => $t->status,
                    'status_label' => Transfer::STATUSES[$t->status] ?? $t->status,
                    'student_id' => $t->student_id,
                    'destination' => $t->other_school_name,
                    'needs_consent' => $t->status === 'awaiting_consent',
                    'consents' => $t->consents()->orderBy('id')->get(['category', 'given', 'decided_at']),
                ]),
            'categories' => TransferConsent::CATEGORIES,
        ]);
    }

    public function parentRequest(Request $request, int $studentId): JsonResponse
    {
        $data = $request->validate([
            'reason' => 'nullable|string|max:1000',
            'requested_transfer_date' => 'nullable|date|after_or_equal:today',
            'other_school_name' => 'nullable|string|max:255',
        ]);

        $student = Student::with('currentEnrollment')->findOrFail($studentId);
        abort_unless($student->isGuardedBy($request->user()), 404);

        return $this->attempt(function () use ($student, $request, $data) {
            $transfer = $this->transfers->startOutgoing($student, $request->user(), 'parent', $data);

            return ['message' => "Request {$transfer->reference} sent to the school.", 'transfer' => ['id' => $transfer->id, 'reference' => $transfer->reference, 'status' => $transfer->status]];
        }, 201);
    }

    public function parentConsent(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'transfer' => 'required|boolean',
            'health' => 'sometimes|boolean',
            'special_needs' => 'sometimes|boolean',
            'discipline' => 'sometimes|boolean',
        ]);

        $transfer = Transfer::where('direction', 'outgoing')->findOrFail($id);

        return $this->attempt(fn () => [
            'message' => $data['transfer'] ? 'Thank you. Your consent is recorded.' : 'Your refusal is recorded and the transfer has stopped.',
            'status' => $this->transfers->recordConsent($transfer, $request->user(), $data, $request->ip())->status,
        ]);
    }

    // ==================================================================
    // Public, by secure link
    // ==================================================================

    public function packageByLink(Request $request, string $token): JsonResponse
    {
        $package = TransferPackage::withoutGlobalScopes()->where('link_token_hash', hash('sha256', $token))->first();

        if (! $package || ! $package->usable()) {
            return response()->json(['message' => 'This link has expired or is not valid. Ask the sending school for a new one.'], 404);
        }

        $package->increment('access_count');
        AuditLog::withoutGlobalScopes()->create(['school_id' => $package->school_id, 'action' => 'transfer.package_opened_by_link', 'auditable_type' => TransferPackage::class, 'auditable_id' => $package->id, 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);

        return response()->json(['package' => json_decode($package->payload, true), 'hash' => $package->payload_hash, 'signature' => $package->signature, 'expires_at' => $package->expires_at->toIso8601String()]);
    }

    // ==================================================================

    private function find(int $id): Transfer
    {
        return Transfer::where('school_id', $this->schoolId())->findOrFail($id);
    }

    private function summary(Transfer $t): array
    {
        $student = $t->student;

        return [
            'id' => $t->id,
            'reference' => $t->reference,
            'direction' => $t->direction,
            'status' => $t->status,
            'status_label' => Transfer::STATUSES[$t->status] ?? $t->status,
            'student_id' => $t->student_id,
            'student_name' => $student?->user?->name ?? ($t->applicant['name'] ?? null),
            'admission_number' => $student?->admission_number ?? ($t->applicant['previous_admission_number'] ?? null),
            'class' => $student?->currentClass?->name ?? ($t->applicant['previous_class'] ?? null),
            'other_school' => $t->other_school_name,
            'on_schoolpilot' => $t->other_school_id !== null,
            'requested_transfer_date' => $t->requested_transfer_date?->toDateString(),
            'requested_by_type' => $t->requested_by_type,
            'partial_term' => $t->partial_term,
            'current_step' => $t->status === 'pending_approval' ? (TransferSetting::STEP_LABELS[$t->steps[$t->current_step] ?? ''] ?? null) : null,
            'missing_clearance' => $t->direction === 'outgoing' ? $t->clearanceItems()->where('status', 'outstanding')->pluck('item') : [],
            'created_at' => $t->created_at?->toIso8601String(),
            'completed_at' => $t->completed_at?->toIso8601String(),
        ];
    }

    private function attempt(callable $action, int $status = 200): JsonResponse
    {
        try {
            return response()->json($action(), $status);
        } catch (AuthorisationFailure $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (DomainRefusal $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }
}
