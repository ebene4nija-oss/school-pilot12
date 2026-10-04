<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AcademicDocument;
use App\Models\AuditLog;
use App\Models\HistoryImportBatch;
use App\Models\RecordCorrectionRequest;
use App\Models\SchoolMapping;
use App\Models\Student;
use App\Models\TermClosure;
use App\Models\TermSnapshot;
use App\Services\History\AcademicRecordService;
use App\Services\History\DocumentService;
use App\Services\History\HistoryImportService;
use App\Support\InAppNotifier;
use App\Support\TeacherScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Exceptions\DomainRefusal;

/**
 * The academic record: profile, term close, corrections, legacy import and
 * official documents (TASK: Student Academic History and School Transfer).
 */
class AcademicHistoryController extends Controller
{
    public function __construct(
        private AcademicRecordService $records,
        private DocumentService $documents,
        private HistoryImportService $imports,
    ) {
    }

    // ==================================================================
    // Profile
    // ==================================================================

    /** Staff view. A teacher sees only students they teach, read-only. */
    public function show(Request $request, int $id): JsonResponse
    {
        $student = $this->studentInScope($request, $id);

        AuditLog::create([
            'school_id' => $student->school_id,
            'user_id' => $request->user()->id,
            'action' => 'academic_history.viewed',
            'auditable_type' => Student::class,
            'auditable_id' => $student->id,
            'ip_address' => $request->ip(),
        ]);

        return response()->json($this->records->profile($student->load('user:id,name', 'currentClass:id,name')));
    }

    /** A student's own history: released terms only. */
    public function mine(Request $request): JsonResponse
    {
        $student = Student::where('user_id', $request->user()->id)->with('user:id,name', 'currentClass:id,name')->firstOrFail();

        return response()->json($this->records->profile($student, publishedOnly: true));
    }

    /** A guardian's view of their own child: released terms only. */
    public function forChild(Request $request, int $studentId): JsonResponse
    {
        $student = Student::with('user:id,name', 'currentClass:id,name')->findOrFail($studentId);
        abort_unless($student->isGuardedBy($request->user()), 404);

        return response()->json($this->records->profile($student, publishedOnly: true));
    }

    public function snapshotVersions(Request $request, int $snapshotId): JsonResponse
    {
        $snapshot = TermSnapshot::where('school_id', $this->schoolId())->findOrFail($snapshotId);
        $this->studentInScope($request, $snapshot->student_id);

        $versions = TermSnapshot::where('school_id', $snapshot->school_id)
            ->where('student_id', $snapshot->student_id)
            ->where(fn ($q) => $snapshot->term_id
                ? $q->where('term_id', $snapshot->term_id)
                : $q->where('session_name', $snapshot->session_name)->where('term_name', $snapshot->term_name))
            ->orderBy('version')
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'version' => $s->version,
                'source' => $s->source,
                'provenance' => $s->provenance,
                'locked_at' => $s->locked_at?->toIso8601String(),
                'superseded_at' => $s->superseded_at?->toIso8601String(),
                'correction_reason' => $s->correction_reason,
                'intact' => $s->intact(),
                'figures' => $s->figures(),
            ]);

        return response()->json(['data' => $versions]);
    }

    // ==================================================================
    // Term close
    // ==================================================================

    public function closeTerm(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $request->validate([
            'term_id' => ['required', Rule::exists('terms', 'id')->where('school_id', $schoolId)],
            'class_id' => ['required', Rule::exists('classes', 'id')->where('school_id', $schoolId)],
            'accept_unapproved' => 'boolean',
        ]);

        try {
            $result = $this->records->closeTerm($schoolId, $request->integer('term_id'), $request->integer('class_id'), $request->user(), $request->boolean('accept_unapproved'));
        } catch (DomainRefusal $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'not_approved'], 409);
        }

        return response()->json([
            'message' => "Term locked: {$result['created']} record(s) written".($result['skipped'] ? ", {$result['skipped']} already locked" : '').'.',
        ] + $result);
    }

    public function closures(Request $request): JsonResponse
    {
        $request->validate(['term_id' => 'required|integer']);

        return response()->json([
            'data' => TermClosure::where('school_id', $this->schoolId())->where('term_id', $request->integer('term_id'))->get(),
        ]);
    }

    /** Verify an imported or uploaded term, or flag it as not matching its source. */
    public function verifySnapshot(Request $request, int $id): JsonResponse
    {
        $request->validate(['status' => 'required|in:verified,discrepancy', 'note' => 'nullable|string|max:1000']);
        $snapshot = TermSnapshot::where('school_id', $this->schoolId())->whereNull('superseded_at')->findOrFail($id);

        if ($snapshot->source !== 'legacy_import' && $snapshot->source !== 'manual_entry') {
            return response()->json(['message' => 'Only imported or hand-entered terms need verifying.'], 409);
        }

        $snapshot->update([
            'provenance' => $request->input('status') === 'verified' ? 'imported_verified_upload' : 'discrepancy',
            'verified_by' => $request->user()->id,
            'verified_at' => now(),
        ]);

        AuditLog::create([
            'school_id' => $snapshot->school_id,
            'user_id' => $request->user()->id,
            'action' => 'term_snapshot.'.$request->input('status'),
            'auditable_type' => TermSnapshot::class,
            'auditable_id' => $snapshot->id,
            'new_values' => ['note' => $request->input('note')],
        ]);

        return response()->json(['message' => 'Recorded.', 'provenance' => $snapshot->provenance]);
    }

    // ==================================================================
    // Legacy import
    // ==================================================================

    public function importTemplate()
    {
        return response($this->imports->template(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="academic-history-template.csv"',
        ]);
    }

    public function importPreview(Request $request): JsonResponse
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt|max:4096']);

        try {
            $batch = $this->imports->preview($this->schoolId(), $request->user(), (string) file_get_contents($request->file('file')->getRealPath()), $request->file('file')->getClientOriginalName());
        } catch (DomainRefusal $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'batch_id' => $batch->id,
            'valid_rows' => $batch->valid_rows,
            'error_rows' => $batch->error_rows,
            'errors' => array_slice($batch->errors ?? [], 0, 100),
            'preview' => array_slice($batch->rows ?? [], 0, 50),
        ]);
    }

    public function importErrors(int $id)
    {
        $batch = HistoryImportBatch::where('school_id', $this->schoolId())->findOrFail($id);

        return response($this->imports->errorReport($batch), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"import-{$batch->id}-errors.csv\"",
        ]);
    }

    public function importCommit(Request $request, int $id): JsonResponse
    {
        $batch = HistoryImportBatch::where('school_id', $this->schoolId())->findOrFail($id);

        try {
            $created = $this->imports->commit($batch, $request->user());
        } catch (DomainRefusal $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json([
            'message' => "{$created} term record(s) imported, marked unverified until checked.".($batch->error_rows ? " {$batch->error_rows} row(s) with errors were not imported; download the error report." : ''),
            'created' => $created,
        ]);
    }

    // ==================================================================
    // Documents
    // ==================================================================

    public function documents(Request $request, int $id): JsonResponse
    {
        $student = $this->studentInScope($request, $id);

        return response()->json([
            'types' => AcademicDocument::TYPES,
            'data' => AcademicDocument::where('school_id', $student->school_id)->where('student_id', $student->id)->orderByDesc('id')
                ->get(['id', 'type', 'reference', 'payload_hash', 'signatory_name', 'issued_at', 'revoked_at', 'revoked_reason', 'replaced_by_id', 'transfer_id']),
        ]);
    }

    public function issueDocument(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'type' => ['required', Rule::in(array_keys(AcademicDocument::TYPES))],
            'signatory_name' => 'nullable|string|max:120',
        ]);

        $student = Student::where('school_id', $this->schoolId())->with('user:id,name', 'currentClass:id,name')->findOrFail($id);
        $document = $this->documents->issue($request->input('type'), $student, $request->user(), null, $request->input('signatory_name'));

        return response()->json(['message' => 'Document issued.', 'document' => $document->only(['id', 'type', 'reference', 'issued_at'])], 201);
    }

    public function documentPdf(Request $request, int $documentId)
    {
        $document = AcademicDocument::where('school_id', $this->schoolId())->findOrFail($documentId);
        $this->studentInScope($request, $document->student_id);

        return response($this->documents->pdf($document), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$document->reference}.pdf\"",
            'Cache-Control' => 'no-store',
        ]);
    }

    /** Parents download documents issued for their own child. */
    public function childDocumentPdf(Request $request, int $documentId)
    {
        $document = AcademicDocument::findOrFail($documentId);
        $student = Student::findOrFail($document->student_id);
        abort_unless($student->isGuardedBy($request->user()) && in_array($document->type, ['transcript', 'transfer_certificate', 'attendance_summary'], true), 404);

        return response($this->documents->pdf($document), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$document->reference}.pdf\"",
            'Cache-Control' => 'no-store',
        ]);
    }

    public function revokeDocument(Request $request, int $documentId): JsonResponse
    {
        $request->validate(['reason' => 'required|string|min:3|max:255', 'reissue' => 'boolean']);
        $document = AcademicDocument::where('school_id', $this->schoolId())->whereNull('revoked_at')->findOrFail($documentId);

        $replacement = null;
        if ($request->boolean('reissue')) {
            $student = Student::with('user:id,name', 'currentClass:id,name')->findOrFail($document->student_id);
            $replacement = $this->documents->issue($document->type, $student, $request->user(), null, $document->signatory_name);
        }

        $this->documents->revoke($document, $request->user(), $request->input('reason'), $replacement);

        return response()->json(['message' => $replacement ? "Withdrawn and replaced by {$replacement->reference}." : 'Withdrawn.']);
    }

    /** Public: is this document genuine? Minimum information only (§5.3). */
    public function verifyDocument(Request $request, string $reference): JsonResponse
    {
        $result = $this->documents->verify($reference, $request->query('h'));

        return response()->json($result, $result['found'] ? 200 : 404);
    }

    // ==================================================================
    // Error reports from students and parents (§4.6)
    // ==================================================================

    public function reportError(Request $request, int $studentId): JsonResponse
    {
        $request->validate(['description' => 'required|string|min:10|max:2000', 'term_snapshot_id' => 'nullable|integer']);
        $user = $request->user();
        $student = Student::findOrFail($studentId);

        $own = $student->user_id === $user->id || $student->isGuardedBy($user);
        abort_unless($own, 404);

        $row = RecordCorrectionRequest::create([
            'school_id' => $student->school_id,
            'student_id' => $student->id,
            'term_snapshot_id' => $request->input('term_snapshot_id'),
            'raised_by' => $user->id,
            'description' => $request->input('description'),
        ]);

        $admins = \Illuminate\Support\Facades\DB::table('user_profiles')->where('school_id', $student->school_id)->where('role', 'school_admin')->pluck('user_id')->all();
        InAppNotifier::send($student->school_id, $admins, 'record_correction', 'Record error reported', 'A family has reported an error in a student record. Review it under Academic history.');

        return response()->json(['message' => 'Thank you. The school will review this and reply.', 'id' => $row->id], 201);
    }

    public function correctionRequests(Request $request): JsonResponse
    {
        return response()->json([
            'data' => RecordCorrectionRequest::where('school_id', $this->schoolId())
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
                ->orderByDesc('id')->limit(200)->get(),
        ]);
    }

    public function resolveCorrectionRequest(Request $request, int $id): JsonResponse
    {
        $request->validate(['status' => 'required|in:resolved,declined', 'resolution' => 'required|string|min:3|max:2000']);
        $row = RecordCorrectionRequest::where('school_id', $this->schoolId())->where('status', 'open')->findOrFail($id);
        $row->update(['status' => $request->input('status'), 'resolution' => $request->input('resolution'), 'resolved_by' => $request->user()->id, 'resolved_at' => now()]);

        InAppNotifier::send($row->school_id, [(int) $row->raised_by], 'record_correction', 'Your report was reviewed', 'The school has responded to the error you reported. Sign in to read the reply.');

        return response()->json(['message' => 'Saved.']);
    }

    // ==================================================================
    // Class and subject mappings (§9.4)
    // ==================================================================

    public function mappings(): JsonResponse
    {
        return response()->json(['data' => SchoolMapping::where('school_id', $this->schoolId())->orderBy('kind')->orderBy('external_name')->get(), 'types' => SchoolMapping::TYPES]);
    }

    public function saveMapping(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $data = $request->validate([
            'kind' => 'required|in:class,subject',
            'external_name' => 'required|string|max:120',
            'local_id' => 'nullable|integer',
            'mapping_type' => ['required', Rule::in(SchoolMapping::TYPES)],
        ]);

        if ($data['local_id'] ?? null) {
            $table = $data['kind'] === 'class' ? 'classes' : 'subjects';
            abort_unless(\Illuminate\Support\Facades\DB::table($table)->where('school_id', $schoolId)->where('id', $data['local_id'])->exists(), 422, 'That is not one of this school\'s '.$table.'.');
        }

        $mapping = SchoolMapping::updateOrCreate(
            ['school_id' => $schoolId, 'kind' => $data['kind'], 'external_name' => $data['external_name']],
            ['local_id' => $data['local_id'] ?? null, 'mapping_type' => $data['mapping_type']]
        );

        return response()->json(['message' => 'Mapping saved. Original names and scores are always kept.', 'mapping' => $mapping]);
    }

    // ==================================================================

    private function studentInScope(Request $request, int $id): Student
    {
        $user = $request->user();
        $student = Student::where('school_id', $this->schoolId())->findOrFail($id);
        $role = $user->userProfile?->role;

        if ($role === 'teacher' && ! TeacherScope::seesWholeSchool($user) && ! TeacherScope::teaches($user, $student)) {
            abort(404);
        }

        abort_unless(in_array($role, ['school_admin', 'super_admin', 'teacher'], true), 404);

        return $student;
    }
}
