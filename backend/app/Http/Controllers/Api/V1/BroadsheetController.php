<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Broadsheet;
use App\Models\BroadsheetConfiguration;
use App\Models\BroadsheetCorrection;
use App\Models\BroadsheetEvent;
use App\Models\BroadsheetExport;
use App\Models\BroadsheetVersion;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Term;
use App\Models\User;
use App\Services\Results\AuthorisationFailure;
use App\Services\Results\BroadsheetExporter;
use App\Services\Results\BroadsheetWorkflow;
use App\Services\Results\ResultCalculator;
use App\Services\Sections\HeadResolver;
use App\Support\Sections;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * The broadsheet: consolidated class results, their review, and the record of
 * what was approved (TASK/SchoolPilot Comprehensive Broadsheet Management).
 *
 * Access is decided here per class and per subject, never by role alone (§15):
 * a head sees their own levels, a class teacher their own class, a subject
 * teacher only their subject's sheet. Parents and students never reach this
 * controller; they see published results through their portals.
 */
class BroadsheetController extends Controller
{
    public function __construct(
        private BroadsheetWorkflow $workflow,
        private ResultCalculator $calculator,
        private HeadResolver $heads,
    ) {
    }

    // ==================================================================
    // Dashboard
    // ==================================================================

    public function dashboard(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $request->validate([
            'term_id' => ['required', Rule::exists('terms', 'id')->where('school_id', $schoolId)],
            'level_group' => ['nullable', Rule::in(array_keys(Sections::LEVEL_GROUPS))],
        ]);

        $user = $request->user();
        $termId = (int) $request->input('term_id');

        $classes = SchoolClass::where('school_id', $schoolId)
            ->orderBy('order_index')
            ->orderBy('name')
            ->get()
            ->filter(fn ($c) => ! $request->filled('level_group') || Sections::levelGroupOf($c->level_category) === $request->input('level_group'))
            ->filter(fn ($c) => $this->canViewClass($user, $schoolId, $c))
            ->values();

        $broadsheets = Broadsheet::where('school_id', $schoolId)->where('term_id', $termId)->get()->keyBy('class_id');

        $rows = [];
        $totals = ['students' => 0, 'completed' => 0, 'pending' => 0, 'submitted' => 0, 'approved' => 0, 'published' => 0];
        $averages = [];

        foreach ($classes as $class) {
            $calc = $this->calculator->forClass($schoolId, $termId, $class->id, ['attendance' => false]);
            $bs = $broadsheets->get($class->id);
            $students = count($calc['students']);
            $status = $bs?->status ?? 'not_started';

            $row = [
                'class_id' => $class->id,
                'class' => $class->name,
                'level_group' => Sections::levelGroupOf($class->level_category),
                'broadsheet_id' => $bs?->id,
                'status' => $status,
                'current_step' => $bs ? ($bs->steps[$bs->current_step]['label'] ?? null) : null,
                'students' => $students,
                'completed' => $calc['class_stats']['complete'],
                'pending' => $calc['class_stats']['incomplete'],
                'submitted' => in_array($status, ['in_review', 'approved', 'published'], true) ? $students : 0,
                'approved' => in_array($status, ['approved', 'published'], true) ? $students : 0,
                'published' => $status === 'published' ? $students : 0,
                'class_average' => $calc['class_stats']['class_average'],
                'highest_average' => $calc['class_stats']['highest_average'],
                'lowest_average' => $calc['class_stats']['lowest_average'],
                'subjects_submitted' => $bs ? $bs->submissions()->where('status', 'submitted')->count() : 0,
                'subjects_total' => count($calc['subjects']),
            ];

            foreach (array_keys($totals) as $k) {
                $totals[$k] += $row[$k];
            }

            foreach ($calc['students'] as $s) {
                if ($s['average'] !== null) {
                    $averages[] = $s['average'];
                }
            }

            $rows[] = $row;
        }

        return response()->json([
            'summary' => $totals + [
                'class_average' => $averages ? round(array_sum($averages) / count($averages), 2) : null,
                'highest_average' => $averages ? max($averages) : null,
                'lowest_average' => $averages ? min($averages) : null,
            ],
            'classes' => $rows,
        ]);
    }

    // ==================================================================
    // Class and subject broadsheets
    // ==================================================================

    public function showClass(Request $request): JsonResponse
    {
        [$schoolId, $term, $class] = $this->termAndClass($request);
        $user = $request->user();

        if (! $this->canViewClass($user, $schoolId, $class)) {
            return $this->forbidden('You can view the broadsheet only for classes you head or teach as class teacher.');
        }

        $broadsheet = $this->workflow->open($schoolId, $term->id, $class->id);
        $calc = $this->workflow->calculate($broadsheet);
        $validation = $this->workflow->validation($broadsheet, $calc);

        return response()->json([
            'broadsheet' => $this->broadsheetSummary($broadsheet, $user, $calc),
            'calculation' => $calc,
            'validation' => [
                'errors' => $validation['errors'],
                'warnings' => $validation['warnings'],
                'notices' => $validation['notices'],
                'unresolved_warnings' => $validation['unresolved_warnings'],
            ],
            // Never a draft dressed as approved (spec §18 rule 4).
            'is_provisional' => ! $broadsheet->isLocked(),
        ]);
    }

    public function showSubject(Request $request): JsonResponse
    {
        [$schoolId, $term, $class] = $this->termAndClass($request);
        $request->validate(['subject_id' => ['required', Rule::exists('subjects', 'id')->where('school_id', $schoolId)]]);
        $subjectId = (int) $request->input('subject_id');
        $user = $request->user();

        if (! $this->canViewSubject($user, $schoolId, $class, $subjectId)) {
            return $this->forbidden('You can view this subject only if you teach it in this class or review it.');
        }

        $broadsheet = $this->workflow->open($schoolId, $term->id, $class->id);
        $calc = $this->workflow->calculate($broadsheet);

        $subject = collect($calc['subjects'])->firstWhere('id', $subjectId);
        $submission = $broadsheet->submissions()->where('subject_id', $subjectId)->first();

        $students = collect($calc['students'])
            ->filter(fn ($s) => ($s['results'][$subjectId]['state'] ?? 'not_offered') !== 'not_offered')
            ->map(fn ($s) => [
                'id' => $s['id'],
                'name' => $s['name'],
                'admission_number' => $s['admission_number'],
                'result' => $s['results'][$subjectId],
                'editable' => $submission ? $submission->allowsEditFor($s['id']) && ! $broadsheet->isLocked() : ! $broadsheet->isLocked(),
            ])
            ->values();

        return response()->json([
            'broadsheet_id' => $broadsheet->id,
            'status' => $broadsheet->status,
            'subject' => $subject,
            'scheme' => $calc['scheme'],
            'submission' => [
                'status' => $submission?->status ?? 'draft',
                'returned_student_ids' => $submission?->returned_student_ids,
                'submitted_at' => $submission?->submitted_at?->toIso8601String(),
            ],
            'can_submit' => in_array($broadsheet->status, ['draft', 'returned', 'rejected'], true)
                && ($submission?->status ?? 'draft') !== 'submitted'
                && $this->heads->canAct($user, $schoolId, 'subject_teacher', $class->id, $broadsheet->level_group, $subjectId),
            'students' => $students,
            'stats' => $calc['subject_stats'][$subjectId] ?? null,
        ]);
    }

    public function validation(Request $request, int $id): JsonResponse
    {
        $broadsheet = $this->findViewable($request, $id);

        return response()->json($this->workflow->validation($broadsheet));
    }

    // ==================================================================
    // Workflow actions
    // ==================================================================

    public function submitSubject(Request $request, int $id, int $subjectId): JsonResponse
    {
        $broadsheet = $this->find($id);

        return $this->attempt(fn () => [
            'message' => 'Subject handed in for review. Its marks are now locked.',
            'submission' => $this->workflow->submitSubject($broadsheet, $subjectId, $request->user()),
            'broadsheet' => $this->broadsheetSummary($broadsheet->fresh(), $request->user()),
        ]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $request->validate(['note' => 'nullable|string|max:1000']);
        $broadsheet = $this->find($id);

        return $this->attempt(function () use ($request, $broadsheet) {
            $result = $this->workflow->approve($broadsheet, $request->user(), $request->input('note'));

            return [
                'message' => $result['version'] ? "Approved. Version {$result['version']->version} is now the official record." : 'Step approved.',
                'broadsheet' => $this->broadsheetSummary($result['broadsheet'], $request->user()),
            ];
        });
    }

    public function returnItems(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string|min:3|max:2000',
            'items' => 'required|array|min:1',
            'items.*.subject_id' => 'required|integer',
            'items.*.student_ids' => 'nullable|array',
            'items.*.student_ids.*' => 'integer',
        ]);
        $broadsheet = $this->find($id);

        return $this->attempt(fn () => [
            'message' => 'Returned for correction. Only the returned items are reopened.',
            'broadsheet' => $this->broadsheetSummary($this->workflow->returnItems($broadsheet, $request->user(), $request->input('items'), $request->input('reason')), $request->user()),
        ]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $request->validate(['reason' => 'required|string|min:3|max:2000']);
        $broadsheet = $this->find($id);

        return $this->attempt(fn () => [
            'message' => 'Rejected. Every subject is reopened.',
            'broadsheet' => $this->broadsheetSummary($this->workflow->reject($broadsheet, $request->user(), $request->input('reason')), $request->user()),
        ]);
    }

    public function override(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'code' => 'required|string|max:60',
            'key' => 'required|string|max:120',
            'reason' => 'required|string|min:5|max:1000',
        ]);
        $broadsheet = $this->find($id);

        return $this->attempt(fn () => [
            'message' => 'Warning accepted, with your reason on record.',
            'override' => $this->workflow->override($broadsheet, $request->user(), $request->input('code'), $request->input('key'), $request->input('reason')),
        ]);
    }

    public function publish(Request $request, int $id): JsonResponse
    {
        $broadsheet = $this->find($id);

        return $this->attempt(fn () => [
            'message' => 'Published. Students and parents can now see these results.',
            'broadsheet' => $this->broadsheetSummary($this->workflow->publish($broadsheet, $request->user()), $request->user()),
        ]);
    }

    // ==================================================================
    // Corrections
    // ==================================================================

    public function requestCorrection(Request $request, int $id): JsonResponse
    {
        $schoolId = $this->schoolId();
        $request->validate([
            'student_id' => ['required', Rule::exists('students', 'id')->where('school_id', $schoolId)],
            'subject_id' => ['required', Rule::exists('subjects', 'id')->where('school_id', $schoolId)],
            'components' => 'nullable|array',
            'result_state' => 'nullable|in:scored,absent,exempt',
            'reason' => 'required|string|min:5|max:2000',
        ]);

        if (! $request->has('components') && ! $request->has('result_state')) {
            return response()->json(['message' => 'Give the corrected marks or the corrected state.'], 422);
        }

        $broadsheet = $this->find($id);

        return $this->attempt(fn () => [
            'message' => 'Correction requested. It changes nothing until it is approved.',
            'correction' => $this->workflow->requestCorrection(
                $broadsheet,
                $request->user(),
                (int) $request->input('student_id'),
                (int) $request->input('subject_id'),
                array_filter($request->only(['components', 'result_state']), fn ($v) => $v !== null),
                $request->input('reason')
            ),
        ], 201);
    }

    public function corrections(Request $request, int $id): JsonResponse
    {
        $broadsheet = $this->findViewable($request, $id);

        $rows = BroadsheetCorrection::where('broadsheet_id', $broadsheet->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn ($c) => $c->toArray() + [
                'student_name' => DB::table('students')->join('users', 'users.id', '=', 'students.user_id')->where('students.id', $c->student_id)->value('users.name'),
                'subject_name' => DB::table('subjects')->where('id', $c->subject_id)->value('name'),
                'requested_by_name' => User::find($c->requested_by)?->name,
                'decided_by_name' => $c->decided_by ? User::find($c->decided_by)?->name : null,
            ]);

        return response()->json(['data' => $rows]);
    }

    public function decideCorrection(Request $request, int $correctionId): JsonResponse
    {
        $request->validate(['approve' => 'required|boolean', 'note' => 'nullable|string|max:1000']);
        $correction = BroadsheetCorrection::where('school_id', $this->schoolId())->findOrFail($correctionId);

        return $this->attempt(fn () => [
            'message' => $request->boolean('approve') ? 'Correction applied. A new version replaces the old one, which is kept.' : 'Correction rejected.',
            'correction' => $this->workflow->decideCorrection($correction, $request->user(), $request->boolean('approve'), $request->input('note')),
        ]);
    }

    // ==================================================================
    // History, versions, exports
    // ==================================================================

    public function history(Request $request, int $id): JsonResponse
    {
        $broadsheet = $this->findViewable($request, $id);
        $names = User::whereIn('id', BroadsheetEvent::where('broadsheet_id', $broadsheet->id)->pluck('user_id')->filter())->pluck('name', 'id');

        return response()->json([
            'events' => BroadsheetEvent::where('broadsheet_id', $broadsheet->id)->orderBy('id')->get()
                ->map(fn ($e) => $e->toArray() + ['user_name' => $names[$e->user_id] ?? null]),
            'versions' => BroadsheetVersion::where('broadsheet_id', $broadsheet->id)->orderByDesc('version')->get()
                ->map(fn ($v) => [
                    'version' => $v->version,
                    'approved_at' => $v->approved_at?->toIso8601String(),
                    'approved_by' => $v->approver?->name,
                    'superseded_at' => $v->superseded_at?->toIso8601String(),
                    'superseded_reason' => $v->superseded_reason,
                    'validation' => $v->validation,
                    'intact' => $v->intact(),
                ]),
            'exports' => BroadsheetExport::where('broadsheet_id', $broadsheet->id)->orderByDesc('id')->with('user:id,name')->get(),
        ]);
    }

    public function version(Request $request, int $id, int $version): JsonResponse
    {
        $broadsheet = $this->findViewable($request, $id);
        $row = BroadsheetVersion::where('broadsheet_id', $broadsheet->id)->where('version', $version)->firstOrFail();

        if (! $row->intact()) {
            return response()->json(['message' => 'This version no longer matches its recorded fingerprint and cannot be shown as official.'], 409);
        }

        return response()->json(['version' => $row->version, 'superseded_at' => $row->superseded_at, 'snapshot' => $row->data()]);
    }

    public function export(Request $request, int $id)
    {
        $request->validate([
            'format' => 'required|in:csv,pdf',
            'kind' => 'nullable|in:class,subject',
            'subject_id' => 'nullable|integer',
            'version' => 'nullable|integer',
            'paper' => 'nullable|in:a4,a3',
            'orientation' => 'nullable|in:landscape,portrait',
        ]);

        $broadsheet = $this->findViewable($request, $id);
        $kind = $request->input('kind', 'class');

        // Exports are restricted further than viewing: subject teachers get
        // their own subject's sheet only.
        if ($kind === 'class' && ! $this->canViewClass($request->user(), $broadsheet->school_id, $broadsheet->schoolClass)) {
            return $this->forbidden('You can export only the subject sheets you teach.');
        }

        $version = BroadsheetVersion::where('broadsheet_id', $broadsheet->id)
            ->when($request->filled('version'), fn ($q) => $q->where('version', $request->integer('version')), fn ($q) => $q->whereNull('superseded_at'))
            ->orderByDesc('version')
            ->first();

        if (! $version) {
            return response()->json(['message' => 'Only approved results can be exported. This broadsheet has no approved version yet.', 'code' => 'not_approved'], 409);
        }

        if (! $version->intact()) {
            return response()->json(['message' => 'This version no longer matches its recorded fingerprint.'], 409);
        }

        $snapshot = $version->data();
        $snapshot['approvals'] = $this->approvalSignatures($broadsheet, $version);

        $exporter = app(BroadsheetExporter::class);
        $format = $request->input('format');
        $subjectId = $request->filled('subject_id') ? $request->integer('subject_id') : null;

        DB::transaction(function () use ($broadsheet, $version, $format, $kind, $request) {
            BroadsheetExport::create([
                'school_id' => $broadsheet->school_id,
                'broadsheet_id' => $broadsheet->id,
                'version' => $version->version,
                'format' => $format,
                'kind' => $kind,
                'user_id' => $request->user()->id,
            ]);

            $this->workflow->log($broadsheet, 'export', $request->user(), null, ['format' => $format, 'kind' => $kind, 'version' => $version->version]);
        });

        $name = str_replace(' ', '-', strtolower(($snapshot['calculation']['class']['name'] ?? 'class').'-'.$broadsheet->reference.'-v'.$version->version));

        if ($format === 'csv') {
            return response($exporter->csv($snapshot, $kind, $subjectId), 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"{$name}.csv\"",
                'Cache-Control' => 'no-store',
            ]);
        }

        $pdf = $exporter->pdf($snapshot, $kind, $subjectId, [
            'paper' => $request->input('paper', count($snapshot['calculation']['subjects']) > 10 ? 'a3' : 'a4'),
            'orientation' => $request->input('orientation', 'landscape'),
            'school_name' => School::find($broadsheet->school_id)?->name,
        ]);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$name}.pdf\"",
            'Cache-Control' => 'no-store',
        ]);
    }

    // ==================================================================
    // Analytics and consolidated views
    // ==================================================================

    public function analytics(Request $request): JsonResponse
    {
        [$schoolId, $term, $class] = $this->termAndClass($request);

        if (! $this->canViewClass($request->user(), $schoolId, $class)) {
            return $this->forbidden('You can view analytics only for classes you head or teach as class teacher.');
        }

        $current = $this->calculator->forClass($schoolId, $term->id, $class->id, ['attendance' => false]);
        $previousTerm = Term::where('school_id', $schoolId)
            ->where('start_date', '<', $term->start_date->toDateString())
            ->orderByDesc('start_date')
            ->first();

        $comparison = null;

        if ($previousTerm) {
            $previous = $this->calculator->forClass($schoolId, $previousTerm->id, $class->id, ['attendance' => false]);

            // Comparable only under the same marking scheme and grade scale (§11).
            $comparable = array_column($previous['scheme']['parts'], 'max') === array_column($current['scheme']['parts'], 'max')
                && $previous['grading_bands'] === $current['grading_bands'];

            $comparison = [
                'term' => ['id' => $previousTerm->id, 'name' => $previousTerm->name],
                'comparable' => $comparable,
                'note' => $comparable ? null : 'The marking scheme or grade scale changed between these terms, so the figures may not be directly comparable.',
                'class_average' => $previous['class_stats']['class_average'],
                'change_points' => $previous['class_stats']['class_average'] !== null && $current['class_stats']['class_average'] !== null
                    ? round($current['class_stats']['class_average'] - $previous['class_stats']['class_average'], 2)
                    : null,
                'subjects' => collect($current['subject_stats'])->map(function ($s, $subjectId) use ($previous) {
                    $before = $previous['subject_stats'][$subjectId]['average'] ?? null;

                    return [
                        'subject_id' => (int) $subjectId,
                        'subject' => $s['subject'],
                        'average' => $s['average'],
                        'previous_average' => $before,
                        'change_points' => $before !== null && $s['average'] !== null ? round($s['average'] - $before, 2) : null,
                    ];
                })->values(),
            ];
        }

        return response()->json([
            'class' => $current['class'],
            'term' => $current['term'],
            'class_stats' => $current['class_stats'],
            'subject_stats' => array_values($current['subject_stats']),
            'comparison' => $comparison,
            'is_provisional' => ! (Broadsheet::where('school_id', $schoolId)->where('term_id', $term->id)->where('class_id', $class->id)->first()?->isLocked() ?? false),
        ]);
    }

    /**
     * Annual broadsheet: each term's average and the session average, per
     * student. Approved figures where a term has them, live ones labelled
     * provisional otherwise.
     */
    public function annual(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $request->validate([
            'session_id' => ['required', Rule::exists('academic_sessions', 'id')->where('school_id', $schoolId)],
            'class_id' => ['required', Rule::exists('classes', 'id')->where('school_id', $schoolId)],
        ]);

        $class = SchoolClass::where('school_id', $schoolId)->findOrFail($request->integer('class_id'));

        if (! $this->canViewClass($request->user(), $schoolId, $class)) {
            return $this->forbidden('You can view this only for classes you head or teach as class teacher.');
        }

        return response()->json($this->annualFigures($schoolId, $request->integer('session_id'), $class));
    }

    /**
     * Promotion broadsheet: recommendations from configurable rules, for a
     * person to decide. Nobody is promoted from here (spec §21, history §4.4).
     */
    public function promotion(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $request->validate([
            'session_id' => ['required', Rule::exists('academic_sessions', 'id')->where('school_id', $schoolId)],
            'class_id' => ['required', Rule::exists('classes', 'id')->where('school_id', $schoolId)],
            'min_average' => 'nullable|numeric|min:0|max:100',
            'max_failed_core' => 'nullable|integer|min:0|max:20',
        ]);

        $class = SchoolClass::where('school_id', $schoolId)->findOrFail($request->integer('class_id'));

        if (! $this->canViewClass($request->user(), $schoolId, $class)) {
            return $this->forbidden('You can view this only for classes you head or teach as class teacher.');
        }

        $annual = $this->annualFigures($schoolId, $request->integer('session_id'), $class);
        $minAverage = (float) $request->input('min_average', $annual['pass_mark']);
        $maxFailedCore = (int) $request->input('max_failed_core', 1);

        $students = collect($annual['students'])->map(function ($s) use ($minAverage, $maxFailedCore) {
            $reasons = [];

            if ($s['session_average'] === null) {
                $recommendation = 'review';
                $reasons[] = 'No results recorded for the session.';
            } else {
                if ($s['session_average'] < $minAverage) {
                    $reasons[] = "Session average {$s['session_average']} is below {$minAverage}.";
                }
                if ($s['failed_core_subjects'] > $maxFailedCore) {
                    $reasons[] = "{$s['failed_core_subjects']} compulsory subject(s) below the pass mark in the last term.";
                }
                if ($s['terms_missing'] > 0) {
                    $reasons[] = "{$s['terms_missing']} term(s) without results.";
                }

                $recommendation = $reasons === [] ? 'promote' : ($s['terms_missing'] > 0 ? 'review' : 'repeat');
            }

            return $s + ['recommendation' => $recommendation, 'reasons' => $reasons];
        })->values();

        return response()->json($annual + [
            'rules' => ['min_average' => $minAverage, 'max_failed_core' => $maxFailedCore],
            'students' => $students,
            'note' => 'These are recommendations only. Every promotion is confirmed by a member of staff.',
        ]);
    }

    /** Master broadsheet: one row per class across the school or a level. */
    public function master(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $this->seesWholeSchool($user, $this->schoolId())) {
            return $this->forbidden('The master broadsheet is for the principal, school owner and administrators.');
        }

        return $this->dashboard($request);
    }

    // ==================================================================
    // Configuration
    // ==================================================================

    public function config(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();

        return response()->json([
            'defaults' => BroadsheetConfiguration::DEFAULTS,
            'level_groups' => Sections::LEVEL_GROUPS,
            'effective' => collect(array_merge([null], array_keys(Sections::LEVEL_GROUPS)))
                ->mapWithKeys(fn ($g) => [$g ?? 'default' => BroadsheetConfiguration::resolve($schoolId, $g)]),
            'rows' => BroadsheetConfiguration::where('school_id', $schoolId)->get(),
        ]);
    }

    public function updateConfig(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $data = $request->validate([
            'level_group' => ['nullable', Rule::in(array_keys(Sections::LEVEL_GROUPS))],
            'display_mode' => 'sometimes|in:total,breakdown,grade,score_grade,detailed',
            'ranking_basis' => 'sometimes|in:average,total,none',
            'ranking_method' => 'sometimes|in:competition,dense',
            'ranking_scope' => 'sometimes|in:all,complete_only',
            'absence_policy' => 'sometimes|in:exclude,zero',
            'pass_mark' => 'sometimes|integer|min:0|max:100',
            'show_gender' => 'sometimes|boolean',
            'show_attendance' => 'sometimes|boolean',
            'show_remarks' => 'sometimes|boolean',
            'numeric_averages' => 'sometimes|boolean',
            'subject_order' => 'sometimes|nullable|array',
            'subject_order.*' => 'integer',
            'confidentiality_footer' => 'sometimes|nullable|string|max:200',
        ]);

        $levelGroup = $data['level_group'] ?? null;
        unset($data['level_group']);

        $row = BroadsheetConfiguration::where('school_id', $schoolId)
            ->where(fn ($q) => $levelGroup === null ? $q->whereNull('level_group') : $q->where('level_group', $levelGroup))
            ->first() ?? new BroadsheetConfiguration(['school_id' => $schoolId, 'level_group' => $levelGroup]);

        $old = $row->exists ? $row->toArray() : null;
        $row->fill($data)->save();

        AuditLog::create([
            'school_id' => $schoolId,
            'user_id' => $request->user()->id,
            'action' => 'broadsheet.config_updated',
            'auditable_type' => BroadsheetConfiguration::class,
            'auditable_id' => $row->id,
            'old_values' => $old,
            'new_values' => $row->toArray(),
        ]);

        return response()->json([
            'message' => 'Broadsheet settings saved. Approved broadsheets keep the settings they were approved under.',
            'effective' => BroadsheetConfiguration::resolve($schoolId, $levelGroup),
        ]);
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    private function annualFigures(int $schoolId, int $sessionId, SchoolClass $class): array
    {
        $terms = Term::where('school_id', $schoolId)->where('session_id', $sessionId)->orderBy('start_date')->get();
        $broadsheets = Broadsheet::where('school_id', $schoolId)->where('class_id', $class->id)->whereIn('term_id', $terms->pluck('id'))->get()->keyBy('term_id');

        $perTerm = [];

        foreach ($terms as $term) {
            $bs = $broadsheets->get($term->id);
            $version = $bs?->isLocked() ? $bs->latestVersion() : null;

            $perTerm[$term->id] = $version
                ? ['calc' => $version->data()['calculation'], 'provisional' => false]
                : ['calc' => $this->calculator->forClass($schoolId, $term->id, $class->id, ['attendance' => false]), 'provisional' => true];
        }

        $lastCalc = $terms->isNotEmpty() ? $perTerm[$terms->last()->id]['calc'] : null;
        $passMark = (int) ($lastCalc['config']['pass_mark'] ?? 40);

        $studentIds = collect($perTerm)->flatMap(fn ($t) => array_column($t['calc']['students'], 'id'))->unique()->values();
        $names = collect($perTerm)->flatMap(fn ($t) => collect($t['calc']['students'])->mapWithKeys(fn ($s) => [$s['id'] => [$s['name'], $s['admission_number']]]));

        $students = $studentIds->map(function ($id) use ($terms, $perTerm, $names, $lastCalc, $passMark) {
            $averages = [];
            $missing = 0;

            foreach ($terms as $term) {
                $row = collect($perTerm[$term->id]['calc']['students'])->firstWhere('id', $id);
                $averages[$term->id] = $row['average'] ?? null;

                if (($row['average'] ?? null) === null) {
                    $missing++;
                }
            }

            $values = array_filter($averages, fn ($v) => $v !== null);
            $last = $lastCalc ? collect($lastCalc['students'])->firstWhere('id', $id) : null;
            $failedCore = 0;

            if ($last) {
                foreach ($lastCalc['subjects'] as $subject) {
                    $r = $last['results'][$subject['id']] ?? null;
                    if ($subject['is_compulsory'] && $r && $r['state'] === 'scored' && $r['total'] < $passMark) {
                        $failedCore++;
                    }
                }
            }

            return [
                'id' => $id,
                'name' => $names[$id][0] ?? null,
                'admission_number' => $names[$id][1] ?? null,
                'term_averages' => $averages,
                'session_average' => $values ? round(array_sum($values) / count($values), 2) : null,
                'terms_missing' => $missing,
                'failed_core_subjects' => $failedCore,
            ];
        })->sortBy('name')->values()->all();

        $positions = $this->calculator->positions(
            collect($students)->filter(fn ($s) => $s['session_average'] !== null)->map(fn ($s) => $s['session_average'])->all(),
            $lastCalc['config']['ranking_method'] ?? 'competition'
        );

        foreach ($students as $i => $s) {
            $students[$i]['session_position'] = $positions[$i] ?? null;
        }

        return [
            'class' => ['id' => $class->id, 'name' => $class->name],
            'terms' => $terms->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'provisional' => $perTerm[$t->id]['provisional']])->values(),
            'pass_mark' => $passMark,
            'students' => $students,
        ];
    }

    private function approvalSignatures(Broadsheet $broadsheet, BroadsheetVersion $version): array
    {
        $events = BroadsheetEvent::where('broadsheet_id', $broadsheet->id)
            ->whereIn('action', ['compile', 'approve', 'correction_applied'])
            ->where('created_at', '<=', $version->approved_at ?? now())
            ->orderBy('id')
            ->get();

        $names = User::whereIn('id', $events->pluck('user_id')->filter())->pluck('name', 'id');

        return $events->map(fn ($e) => [
            'label' => $e->step_role ? (Sections::STEP_ROLES[$e->step_role] ?? $e->step_role) : 'Correction approved',
            'name' => $names[$e->user_id] ?? '',
            'at' => $e->created_at?->format('j M Y'),
        ])->unique(fn ($a) => $a['label'].$a['name'])->values()->all();
    }

    private function broadsheetSummary(Broadsheet $broadsheet, User $user, ?array $calc = null): array
    {
        $calc ??= $this->calculator->forClass($broadsheet->school_id, $broadsheet->term_id, $broadsheet->class_id, ['attendance' => false]);
        $submissions = $broadsheet->submissions()->get()->keyBy('subject_id');

        $steps = collect($broadsheet->steps)->map(function ($step, $i) use ($broadsheet) {
            $holders = $step['role'] === 'subject_teacher' || $step['role'] === 'hod'
                ? []
                : $this->heads->holders($broadsheet->school_id, $step['role'], $broadsheet->class_id, $broadsheet->level_group);

            return $step + [
                'index' => $i,
                'state' => match (true) {
                    in_array($broadsheet->status, ['approved', 'published'], true) => 'done',
                    $i < $broadsheet->current_step => 'done',
                    $i === $broadsheet->current_step && $broadsheet->status === 'in_review' => 'current',
                    $i === 0 && in_array($broadsheet->status, ['draft', 'returned', 'rejected'], true) => 'current',
                    default => 'waiting',
                },
                'holders' => User::whereIn('id', $holders)->pluck('name')->all(),
            ];
        })->values();

        return [
            'id' => $broadsheet->id,
            'reference' => $broadsheet->reference,
            'term_id' => $broadsheet->term_id,
            'class_id' => $broadsheet->class_id,
            'level_group' => $broadsheet->level_group,
            'status' => $broadsheet->status,
            'current_step' => $broadsheet->current_step,
            'current_version' => $broadsheet->current_version,
            'report_cards_stale' => $broadsheet->report_cards_stale,
            'published_at' => $broadsheet->published_at?->toIso8601String(),
            'steps' => $steps,
            'subjects' => collect($calc['subjects'])->map(fn ($s) => $s + [
                'submission_status' => $submissions->get($s['id'])?->status ?? 'draft',
                'returned_student_ids' => $submissions->get($s['id'])?->returned_student_ids,
            ])->values(),
            'can_act' => $this->workflow->canActNow($broadsheet, $user),
            'can_publish' => $broadsheet->status === 'approved' && $this->workflow->canDecideCorrections($broadsheet, $user),
            'can_decide_corrections' => $this->workflow->canDecideCorrections($broadsheet, $user),
            'pending_corrections' => BroadsheetCorrection::where('broadsheet_id', $broadsheet->id)->where('status', 'pending')->count(),
        ];
    }

    /** @return array{0: int, 1: Term, 2: SchoolClass} */
    private function termAndClass(Request $request): array
    {
        $schoolId = $this->schoolId();
        $request->validate([
            'term_id' => ['required', Rule::exists('terms', 'id')->where('school_id', $schoolId)],
            'class_id' => ['required', Rule::exists('classes', 'id')->where('school_id', $schoolId)],
        ]);

        return [
            $schoolId,
            Term::where('school_id', $schoolId)->findOrFail($request->integer('term_id')),
            SchoolClass::where('school_id', $schoolId)->findOrFail($request->integer('class_id')),
        ];
    }

    private function find(int $id): Broadsheet
    {
        return Broadsheet::where('school_id', $this->schoolId())->findOrFail($id);
    }

    private function findViewable(Request $request, int $id): Broadsheet
    {
        $broadsheet = $this->find($id);
        $class = $broadsheet->schoolClass;
        $user = $request->user();

        $viewable = $this->canViewClass($user, $broadsheet->school_id, $class)
            || DB::table('teacher_subjects')
                ->where('school_id', $broadsheet->school_id)
                ->where('teacher_id', $user->id)
                ->where(fn ($q) => $q->where('class_id', $class->id)->orWhereNull('class_id'))
                ->exists();

        abort_unless($viewable, 403, 'You do not have access to this broadsheet.');

        return $broadsheet;
    }

    private function seesWholeSchool(User $user, int $schoolId): bool
    {
        $role = $user->userProfile?->role;

        return in_array($role, ['school_admin', 'super_admin'], true)
            || $this->heads->levelsHeadedBy($user, $schoolId) === null;
    }

    /** Admin, principal, the level's head, an HOD of the level, or the class teacher. */
    private function canViewClass(User $user, int $schoolId, SchoolClass $class): bool
    {
        if ($this->seesWholeSchool($user, $schoolId)) {
            return true;
        }

        $levelGroup = Sections::levelGroupOf($class->level_category);

        if (in_array($user->id, $this->heads->holders($schoolId, 'level_head', $class->id, $levelGroup), true)) {
            return true;
        }

        if (in_array($user->id, $this->heads->holders($schoolId, 'class_teacher', $class->id, $levelGroup), true)) {
            return true;
        }

        // An HOD reviews their department's subjects across the levels they cover.
        return DB::table('section_head_assignments')
            ->where('school_id', $schoolId)
            ->where('user_id', $user->id)
            ->where('position', 'hod')
            ->whereNull('ended_at')
            ->pluck('level_group')
            ->contains(fn ($g) => $g === null || $g === $levelGroup || ($g === 'secondary' && Sections::isSecondaryLevel($levelGroup)));
    }

    private function canViewSubject(User $user, int $schoolId, SchoolClass $class, int $subjectId): bool
    {
        return $this->canViewClass($user, $schoolId, $class)
            || in_array($user->id, $this->heads->holders($schoolId, 'subject_teacher', $class->id, Sections::levelGroupOf($class->level_category), $subjectId), true);
    }

    private function forbidden(string $message): JsonResponse
    {
        return response()->json(['message' => $message], 403);
    }

    /** Run a workflow action, turning its refusals into the right status. */
    private function attempt(callable $action, int $status = 200): JsonResponse
    {
        try {
            return response()->json($action(), $status);
        } catch (AuthorisationFailure $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }
}
