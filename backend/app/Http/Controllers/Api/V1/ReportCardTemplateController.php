<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ReportCardTemplate;
use App\Models\Student;
use App\Models\Term;
use App\Services\ReportCard\ReportCardTemplateService;
use App\Services\ReportCard\TemplateException;
use App\Services\ReportCardPdfService;
use Illuminate\Http\Request;

/**
 * Per-school report card design management.
 *
 * The flow an admin follows: read the contract, code a bundle, import it
 * (lands as a draft), preview it against sample data, then activate it.
 * Activation is a separate, deliberate step — importing a template must never
 * change what a school is currently printing.
 */
class ReportCardTemplateController extends Controller
{
    public function __construct(
        private ReportCardTemplateService $templates,
        private ReportCardPdfService $pdf
    ) {
    }

    private function getSchoolId(Request $request)
    {
        if ($request->user() && $request->user()->userProfile && $request->user()->userProfile->school_id) {
            return $request->user()->userProfile->school_id;
        }

        $tenant = $request->attributes->get('tenant_school');

        return $tenant ? $tenant->id : $request->attributes->get('school_id');
    }

    public function index(Request $request)
    {
        $templates = ReportCardTemplate::withoutGlobalScopes()
            ->where('school_id', $this->getSchoolId($request))
            ->orderByDesc('id')
            ->get(['id', 'name', 'slug', 'version', 'description', 'engine', 'status', 'regions', 'validation_report', 'checksum', 'imported_at']);

        return response()->json([
            'engine' => ReportCardTemplate::ENGINE,
            'templates' => $templates,
            'active_id' => $templates->firstWhere('status', 'active')?->id,
        ]);
    }

    /**
     * Import a bundle, either as a JSON body or as an uploaded .json file.
     *
     * Imports always land as drafts. The validation report tells the admin
     * exactly what the sanitiser stripped, so a designer can fix their markup
     * rather than wonder why a border vanished.
     */
    public function import(Request $request)
    {
        $bundle = null;

        if ($request->hasFile('bundle')) {
            $file = $request->file('bundle');

            if ($file->getSize() > 2 * 1024 * 1024) {
                return response()->json(['error' => 'Template bundle exceeds 2 MB.'], 422);
            }

            $bundle = json_decode(file_get_contents($file->getRealPath()), true);

            if (!is_array($bundle)) {
                return response()->json([
                    'error' => 'Bundle file is not valid JSON: ' . json_last_error_msg(),
                ], 422);
            }
        } else {
            $bundle = $request->input('bundle', $request->all());
        }

        try {
            $template = $this->templates->import($bundle, $this->getSchoolId($request), $request->user()?->id);
        } catch (TemplateException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Template imported as a draft. Preview it, then activate it when you are satisfied.',
            'template' => $template,
        ], 201);
    }

    public function show(Request $request, $id)
    {
        $template = $this->findTemplate($request, $id);

        return response()->json(['template' => $template]);
    }

    /**
     * Render a template as HTML.
     *
     * With no student_id, renders against fictional sample data — the safe
     * default for a designer iterating on a layout, since a preview should not
     * put a real child's record on screen to check a border radius.
     */
    public function preview(Request $request, $id)
    {
        $template = $this->findTemplate($request, $id);

        if ($request->filled('student_id') && $request->filled('term_id')) {
            $schoolId = $this->getSchoolId($request);

            $student = Student::withoutGlobalScopes()
                ->where('school_id', $schoolId)
                ->with(['user', 'currentClass', 'currentArm'])
                ->findOrFail($request->get('student_id'));

            $term = Term::withoutGlobalScopes()
                ->where('school_id', $schoolId)
                ->with('session')
                ->findOrFail($request->get('term_id'));

            $context = $this->templates->buildContext($student, $term);
        } else {
            $context = $this->templates->sampleContext();
        }

        try {
            $html = $this->templates->render($template, $context);
        } catch (TemplateException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        if ($request->get('format') === 'json') {
            return response()->json(['html' => $html]);
        }

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * Make a draft the school's live design. Archives whatever was live.
     */
    public function activate(Request $request, $id)
    {
        $template = $this->findTemplate($request, $id);

        // Rendering against sample data proves the template still works before
        // it becomes the thing every parent receives.
        try {
            $this->templates->render($template, $this->templates->sampleContext());
        } catch (TemplateException $e) {
            return response()->json([
                'error' => 'Template failed to render and cannot be activated: ' . $e->getMessage(),
            ], 422);
        }

        $template->activate();

        return response()->json([
            'message' => "\"{$template->name}\" v{$template->version} is now this school's report card design.",
            'template' => $template->fresh(),
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $template = $this->findTemplate($request, $id);

        if ($template->status === 'active') {
            return response()->json([
                'error' => 'Cannot delete the active design. Activate another template first.',
            ], 422);
        }

        $template->delete();

        return response()->json(['message' => 'Template deleted.']);
    }

    /**
     * A student's report card, rendered through the school's active design.
     */
    public function renderReportCard(Request $request, $studentId, $termId)
    {
        $schoolId = $this->getSchoolId($request);

        $owned = Student::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('id', $studentId)
            ->exists();

        if (!$owned) {
            return response()->json(['error' => 'Student not found.'], 404);
        }

        try {
            if ($request->get('format') === 'pdf') {
                $pdf = $this->pdf->renderPdf((int) $studentId, (int) $termId);

                return response($pdf, 200, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'inline; filename="report-card-' . $studentId . '-' . $termId . '.pdf"',
                ]);
            }

            $html = $this->pdf->renderHtml((int) $studentId, (int) $termId);
        } catch (TemplateException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            // The pending_approval guardrail lands here.
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * The contract, served from the API so a designer building against a
     * specific deployment always gets that deployment's vocabulary rather
     * than a doc that may have drifted.
     */
    public function contract()
    {
        return response()->json([
            'engine' => ReportCardTemplate::ENGINE,
            'documentation' => '/docs/report-card-template-contract.md',
            'limits' => [
                'max_body_bytes' => ReportCardTemplateService::MAX_BODY_BYTES,
                'max_styles_bytes' => ReportCardTemplateService::MAX_STYLES_BYTES,
            ],
            'sample_context' => $this->templates->sampleContext(),
            'default_template' => [
                'body' => $this->templates->defaultTemplateBody(),
                'styles' => $this->templates->defaultTemplateStyles(),
            ],
        ]);
    }

    private function findTemplate(Request $request, $id): ReportCardTemplate
    {
        return ReportCardTemplate::withoutGlobalScopes()
            ->where('school_id', $this->getSchoolId($request))
            ->findOrFail($id);
    }
}
