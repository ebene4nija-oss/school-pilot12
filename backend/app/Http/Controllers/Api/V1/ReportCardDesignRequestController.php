<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\GradingScale;
use App\Models\ReportCardDesignFile;
use App\Models\ReportCardDesignRequest;
use App\Services\GradingService;
use App\Services\ReportCard\ReportCardTemplateService;
use App\Services\ReportCard\TemplateException;
use App\Support\ReportCardBrief;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * A school asking SchoolPilot to design its report card (school side).
 *
 * The school sends its current card, crest and signatures with a structured
 * brief and the grading it uses. SchoolPilot builds the template and uploads
 * it against the request (PlatformReportCardDesignController). The school
 * previews it on sample data and either approves — the template goes live and
 * the grading scales are applied — or sends it back with comments.
 */
class ReportCardDesignRequestController extends Controller
{
    /** Per file. A phone photo of a report card is usually 2–5 MB. */
    private const MAX_FILE_KB = 10240;

    private const MAX_FILES = 12;

    private const MIMES = 'pdf,jpg,jpeg,png,webp,doc,docx';

    public function index()
    {
        $schoolId = $this->schoolId();

        if ($schoolId === null) {
            return response()->json(['message' => 'Choose a school first.'], 409);
        }

        $requests = ReportCardDesignRequest::where('school_id', $schoolId)
            ->with(['files', 'template:id,name,version,status', 'submitter:id,name'])
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return response()->json([
            'open' => $requests->first(fn ($r) => in_array($r->status, ReportCardDesignRequest::OPEN, true)),
            'history' => $requests->reject(fn ($r) => in_array($r->status, ReportCardDesignRequest::OPEN, true))->values(),
            'sections' => ReportCardBrief::SECTIONS,
            'levels' => GradingScale::LEVELS,
            'file_kinds' => ReportCardDesignFile::KINDS,
            'limits' => ['max_files' => self::MAX_FILES, 'max_file_mb' => self::MAX_FILE_KB / 1024, 'types' => self::MIMES],
        ]);
    }

    /**
     * Submit a request. Multipart: `brief` and `grading_scales` as JSON
     * strings (or arrays), and `files[n][file]`, `files[n][kind]`,
     * `files[n][label]`.
     */
    public function store(Request $request)
    {
        $schoolId = $this->schoolId();

        if ($schoolId === null) {
            return response()->json(['message' => 'Choose a school first.'], 409);
        }

        if (ReportCardDesignRequest::where('school_id', $schoolId)->whereIn('status', ReportCardDesignRequest::OPEN)->exists()) {
            return response()->json(['message' => 'Your school already has a design request open. Add files to it, or cancel it first.'], 409);
        }

        $brief = ReportCardBrief::validate($this->jsonInput($request, 'brief') ?? []);
        $scales = ReportCardBrief::validateScales($this->jsonInput($request, 'grading_scales'));
        $this->validateFiles($request, true);

        $designRequest = DB::transaction(function () use ($request, $schoolId, $brief, $scales) {
            $designRequest = ReportCardDesignRequest::create([
                'school_id' => $schoolId,
                'status' => 'submitted',
                'requirements' => $brief,
                'grading_scales' => $scales,
                'submitted_by' => $request->user()->id,
                'submitted_at' => now(),
            ]);

            $this->storeFiles($request, $designRequest);

            return $designRequest;
        });

        $this->audit($request, $designRequest, 'report_card_design.submitted');

        return response()->json([
            'message' => 'Request sent to SchoolPilot. You will see the design here to approve when it is ready.',
            'request' => $designRequest->load('files'),
        ], 201);
    }

    /** More files for an open request — the crest that was forgotten. */
    public function addFiles(Request $request, $id)
    {
        $designRequest = $this->find($id);

        if (! in_array($designRequest->status, ReportCardDesignRequest::OPEN, true)) {
            return response()->json(['message' => 'This request is closed.'], 409);
        }

        $this->validateFiles($request, true, $designRequest->files()->count());
        $this->storeFiles($request, $designRequest);

        return response()->json(['message' => 'Files added.', 'request' => $designRequest->fresh()->load('files')]);
    }

    public function downloadFile($id, $fileId)
    {
        $file = $this->find($id)->files()->findOrFail($fileId);

        return Storage::disk('local')->download($file->path, $file->original_name);
    }

    /**
     * Approve SchoolPilot's design: the template goes live and the grading
     * scales sent with the request are applied. `regrade_term_id` re-grades
     * that term's scores on the new scales (normally the current term).
     */
    public function approve(Request $request, $id, ReportCardTemplateService $templates, GradingService $grading)
    {
        $designRequest = $this->find($id);

        if ($designRequest->status !== 'template_ready' || ! $designRequest->template) {
            return response()->json(['message' => 'There is no design waiting for your approval.'], 409);
        }

        $validated = $request->validate([
            'regrade_term_id' => ['nullable', Rule::exists('terms', 'id')->where('school_id', $designRequest->school_id)],
        ]);

        // Proves it still renders before every parent receives it.
        try {
            $templates->render($designRequest->template, $templates->sampleContext());
        } catch (TemplateException $e) {
            return response()->json(['message' => 'The design failed to render and cannot go live: '.$e->getMessage()], 422);
        }

        $scales = app(GradingScaleController::class);
        $regraded = 0;

        DB::transaction(function () use ($designRequest, $request, $grading, $scales, $validated, &$regraded) {
            $designRequest->template->activate();

            foreach ($designRequest->grading_scales ?? [] as $level => $bands) {
                $regraded += (int) $scales->save(
                    $designRequest->school_id, $level, $bands, 'From report card design request #'.$designRequest->id,
                    $request, $grading, $validated['regrade_term_id'] ?? null,
                );
            }

            $designRequest->update(['status' => 'approved', 'approved_at' => now(), 'school_feedback' => null]);
        });

        $this->purgeFiles($designRequest);
        $this->audit($request, $designRequest, 'report_card_design.approved');

        return response()->json([
            'message' => 'Approved. This is now your report card design'
                .($designRequest->grading_scales ? ', and your grading scales are in use' : '')
                .($regraded ? " ({$regraded} score(s) re-graded)." : '.'),
        ]);
    }

    /**
     * The design SchoolPilot uploaded, on fictional sample data and the
     * grading scale this request asked for — what the school is approving.
     */
    public function preview($id, ReportCardTemplateService $templates)
    {
        $designRequest = $this->find($id);

        abort_unless($designRequest->template, 404, 'No design uploaded yet.');

        $context = $templates->sampleContext();

        if ($designRequest->grading_scales) {
            $scales = $designRequest->grading_scales;
            $context['grading_scale'] = ReportCardTemplateService::scaleForTemplate(
                $scales['junior_secondary'] ?? $scales['senior_secondary'] ?? $scales['default'] ?? reset($scales)
            );
        }

        try {
            $html = $templates->render($designRequest->template, $context);
        } catch (TemplateException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /** Send the design back with what to change. */
    public function requestChanges(Request $request, $id)
    {
        $designRequest = $this->find($id);

        if ($designRequest->status !== 'template_ready') {
            return response()->json(['message' => 'There is no design waiting for your review.'], 409);
        }

        $validated = $request->validate(['feedback' => 'required|string|max:3000']);

        $designRequest->update(['status' => 'changes_requested', 'school_feedback' => $validated['feedback']]);
        $this->audit($request, $designRequest, 'report_card_design.changes_requested');

        return response()->json(['message' => 'Sent back to SchoolPilot with your comments.']);
    }

    public function cancel(Request $request, $id)
    {
        $designRequest = $this->find($id);

        if (! in_array($designRequest->status, ReportCardDesignRequest::OPEN, true)) {
            return response()->json(['message' => 'This request is already closed.'], 409);
        }

        $designRequest->update(['status' => 'cancelled']);
        $this->purgeFiles($designRequest);
        $this->audit($request, $designRequest, 'report_card_design.cancelled');

        return response()->json(['message' => 'Request cancelled and its files deleted.']);
    }

    /**
     * Delete a finished request's files. A sample report card can carry a
     * real child's name and marks; once the design exists it has no purpose.
     */
    public static function purgeFiles(ReportCardDesignRequest $designRequest): void
    {
        foreach ($designRequest->files()->get() as $file) {
            Storage::disk('local')->delete($file->path);
            $file->delete();
        }

        $designRequest->update(['files_purged_at' => now()]);
    }

    private function find($id): ReportCardDesignRequest
    {
        return ReportCardDesignRequest::where('school_id', $this->schoolId())->with('template')->findOrFail($id);
    }

    /** A JSON field sent either as a string (multipart) or an array (JSON body). */
    private function jsonInput(Request $request, string $key): mixed
    {
        $value = $request->input($key);

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return json_last_error() === JSON_ERROR_NONE ? $decoded : ['__invalid' => true];
        }

        return $value;
    }

    private function validateFiles(Request $request, bool $required, int $existing = 0): void
    {
        $request->validate([
            'files' => ($required ? 'required' : 'nullable').'|array|max:'.(self::MAX_FILES - $existing),
            'files.*.file' => 'required|file|max:'.self::MAX_FILE_KB.'|mimes:'.self::MIMES,
            'files.*.kind' => 'required|in:'.implode(',', array_keys(ReportCardDesignFile::KINDS)),
            'files.*.label' => 'nullable|string|max:80',
        ], [
            'files.required' => 'Attach at least your current report card (a photo or PDF).',
            'files.max' => 'At most '.self::MAX_FILES.' files per request.',
            'files.*.file.max' => 'Each file must be under '.(self::MAX_FILE_KB / 1024).' MB.',
            'files.*.file.mimes' => 'Files must be PDF, JPG, PNG, WEBP or Word documents.',
        ]);
    }

    private function storeFiles(Request $request, ReportCardDesignRequest $designRequest): void
    {
        foreach ($request->file('files', []) as $i => $entry) {
            $upload = $entry['file'];
            $meta = $request->input("files.{$i}", []);

            ReportCardDesignFile::create([
                'request_id' => $designRequest->id,
                'school_id' => $designRequest->school_id,
                'kind' => $meta['kind'],
                'label' => $meta['label'] ?? null,
                'original_name' => mb_substr($upload->getClientOriginalName(), 0, 200),
                // Private disk, random name: never served directly.
                'path' => $upload->store("report-card-designs/{$designRequest->school_id}/{$designRequest->id}", 'local'),
                'mime' => (string) $upload->getMimeType(),
                'size' => (int) $upload->getSize(),
            ]);
        }
    }

    private function audit(Request $request, ReportCardDesignRequest $designRequest, string $action): void
    {
        AuditLog::create([
            'school_id' => $designRequest->school_id,
            'user_id' => $request->user()->id,
            'action' => $action,
            'auditable_type' => ReportCardDesignRequest::class,
            'auditable_id' => $designRequest->id,
            'new_values' => ['status' => $designRequest->status],
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);
    }
}
