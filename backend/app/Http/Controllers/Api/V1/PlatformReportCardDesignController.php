<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\GradingScale;
use App\Models\ReportCardDesignFile;
use App\Models\ReportCardDesignRequest;
use App\Models\ReportCardTemplate;
use App\Models\School;
use App\Services\ReportCard\ReportCardTemplateService;
use App\Services\ReportCard\TemplateException;
use App\Support\ReportCardBrief;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Report-card design requests, SchoolPilot's side.
 *
 * The queue of schools waiting for a design; each request's brief and files;
 * uploading the finished template against it (imported as a draft for that
 * school, so it is validated and dry-run rendered exactly like any import);
 * and correcting the grading scales before the school approves.
 *
 * The operator never makes a design live. The school approves it, having
 * seen it (ReportCardDesignRequestController::approve).
 */
class PlatformReportCardDesignController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate(['status' => 'nullable|string|max:30']);

        $rows = ReportCardDesignRequest::withoutGlobalScopes()
            ->when($validated['status'] ?? null, fn ($q, $s) => $s === 'open'
                ? $q->whereIn('status', ReportCardDesignRequest::OPEN)
                : $q->where('status', $s))
            ->withCount(['files' => fn ($q) => $q->withoutGlobalScopes()])
            ->with('handler:id,name')
            ->orderByRaw("CASE status WHEN 'submitted' THEN 0 WHEN 'changes_requested' THEN 1 WHEN 'in_progress' THEN 2 WHEN 'template_ready' THEN 3 ELSE 4 END")
            ->orderBy('submitted_at')
            ->limit(200)
            ->get();

        $schools = School::query()->tenants()->whereIn('id', $rows->pluck('school_id'))->pluck('name', 'id');

        return response()->json([
            'data' => $rows->map(fn (ReportCardDesignRequest $r) => [
                'id' => $r->id,
                'school_id' => $r->school_id,
                'school' => $schools[$r->school_id] ?? null,
                'status' => $r->status,
                'submitted_at' => $r->submitted_at?->toIso8601String(),
                'files' => $r->files_count,
                'handled_by' => $r->handler?->name,
                'template_uploaded_at' => $r->template_uploaded_at?->toIso8601String(),
                'approved_at' => $r->approved_at?->toIso8601String(),
            ]),
            'counts' => ReportCardDesignRequest::withoutGlobalScopes()
                ->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
        ]);
    }

    public function show($id)
    {
        $r = $this->find($id);
        $school = School::query()->tenants()->find($r->school_id);

        return response()->json([
            'request' => [
                'id' => $r->id,
                'school' => $school ? ['id' => $school->id, 'name' => $school->name, 'subdomain' => $school->subdomain, 'logo_url' => $school->logo_url] : null,
                'status' => $r->status,
                'requirements' => $r->requirements,
                'grading_scales' => $r->grading_scales,
                'submitted_by' => $r->submitter?->name,
                'submitted_at' => $r->submitted_at?->toIso8601String(),
                'handled_by' => $r->handler?->name,
                'operator_notes' => $r->operator_notes,
                'school_feedback' => $r->school_feedback,
                'template' => $r->template ? $r->template->only(['id', 'name', 'version', 'status', 'validation_report']) : null,
                'template_uploaded_at' => $r->template_uploaded_at?->toIso8601String(),
                'approved_at' => $r->approved_at?->toIso8601String(),
                'files_purged_at' => $r->files_purged_at?->toIso8601String(),
                'files' => $r->files->map(fn (ReportCardDesignFile $f) => $f->only(['id', 'kind', 'label', 'original_name', 'mime', 'size', 'created_at'])),
            ],
            'sections' => ReportCardBrief::SECTIONS,
            'levels' => GradingScale::LEVELS,
            // What the school's cards grade on today, for comparison.
            'current_scales' => collect(GradingScale::LEVELS)->keys()->mapWithKeys(fn ($level) => [
                $level => GradingScale::bandsFor($r->school_id, $level === 'default' ? null : $level),
            ]),
        ]);
    }

    public function downloadFile($id, $fileId)
    {
        $file = $this->find($id)->files->firstWhere('id', (int) $fileId);

        abort_unless($file, 404);

        return Storage::disk('local')->download($file->path, $file->original_name);
    }

    /** "I'm working on this" — so two operators do not build the same card. */
    public function start(Request $request, $id)
    {
        $r = $this->find($id);

        if (! in_array($r->status, ['submitted', 'changes_requested'], true)) {
            return response()->json(['message' => 'Only a new or sent-back request can be started.'], 409);
        }

        $r->update(['status' => 'in_progress', 'handled_by' => $request->user()->id]);
        $this->audit($request, $r, 'platform.report_card_design.started');

        return response()->json(['message' => 'Marked as in progress.']);
    }

    /**
     * Upload the finished template for this school. Same bundle format and
     * checks as any import (docs/report-card-template-contract.md); lands as
     * a draft for the school to preview and approve.
     */
    public function uploadTemplate(Request $request, $id, ReportCardTemplateService $templates)
    {
        $r = $this->find($id);

        if (! in_array($r->status, ['submitted', 'in_progress', 'changes_requested', 'template_ready'], true)) {
            return response()->json(['message' => 'This request is closed.'], 409);
        }

        $request->validate([
            'bundle' => 'required|file|max:2048',
            'note' => 'nullable|string|max:2000',
        ]);

        $bundle = json_decode((string) file_get_contents($request->file('bundle')->getRealPath()), true);

        if (! is_array($bundle)) {
            return response()->json(['message' => 'The bundle is not valid JSON: '.json_last_error_msg()], 422);
        }

        try {
            $template = $templates->import($bundle, $r->school_id, $request->user()->id);
        } catch (TemplateException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // A replaced draft that never went live is removed, not left lying
        // around in the school's template list.
        if ($r->template && $r->template->status === 'draft' && $r->template->id !== $template->id) {
            $r->template->delete();
        }

        $r->update([
            'status' => 'template_ready',
            'template_id' => $template->id,
            'template_uploaded_at' => now(),
            'handled_by' => $r->handled_by ?? $request->user()->id,
            'operator_notes' => $request->input('note', $r->operator_notes),
        ]);

        $this->audit($request, $r, 'platform.report_card_design.template_uploaded');

        return response()->json([
            'message' => "Uploaded as a draft for the school. They will see it to preview and approve.",
            'template' => $template->only(['id', 'name', 'version', 'status', 'validation_report']),
        ], 201);
    }

    /** Correct the grading scales before the school approves. */
    public function updateScales(Request $request, $id)
    {
        $r = $this->find($id);

        if (! in_array($r->status, ReportCardDesignRequest::OPEN, true)) {
            return response()->json(['message' => 'This request is closed.'], 409);
        }

        $scales = ReportCardBrief::validateScales($request->input('grading_scales'));
        $r->update(['grading_scales' => $scales]);
        $this->audit($request, $r, 'platform.report_card_design.scales_updated');

        return response()->json(['message' => 'Grading scales saved. They apply when the school approves.', 'grading_scales' => $scales]);
    }

    public function notes(Request $request, $id)
    {
        $validated = $request->validate(['operator_notes' => 'nullable|string|max:3000']);
        $this->find($id)->update($validated);

        return response()->json(['message' => 'Notes saved.']);
    }

    /** The uploaded design rendered with sample data (never a real child). */
    public function preview($id, ReportCardTemplateService $templates)
    {
        $r = $this->find($id);

        abort_unless($r->template, 404, 'No template uploaded yet.');

        $context = $templates->sampleContext();

        if ($r->grading_scales) {
            $bands = $r->grading_scales['senior_secondary'] ?? $r->grading_scales['default'] ?? reset($r->grading_scales);
            $context['grading_scale'] = ReportCardTemplateService::scaleForTemplate($bands);
        }

        try {
            $html = $templates->render($r->template, $context);
        } catch (TemplateException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    private function find($id): ReportCardDesignRequest
    {
        $r = ReportCardDesignRequest::withoutGlobalScopes()->findOrFail($id);
        $r->setRelation('files', ReportCardDesignFile::withoutGlobalScopes()->where('request_id', $r->id)->orderBy('id')->get());
        $r->setRelation('template', $r->template_id ? ReportCardTemplate::withoutGlobalScopes()->find($r->template_id) : null);

        return $r;
    }

    private function audit(Request $request, ReportCardDesignRequest $r, string $action): void
    {
        AuditLog::create([
            'school_id' => $r->school_id,
            'user_id' => $request->user()->id,
            'action' => $action,
            'auditable_type' => ReportCardDesignRequest::class,
            'auditable_id' => $r->id,
            'new_values' => ['status' => $r->status],
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);
    }
}
