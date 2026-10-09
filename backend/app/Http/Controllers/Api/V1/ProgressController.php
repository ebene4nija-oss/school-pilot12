<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Intervention;
use App\Models\InterventionReview;
use App\Models\ProgressReportExport;
use App\Models\ProgressSetting;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentRemark;
use App\Models\User;
use App\Services\Progress\ProgressService;
use App\Support\CurrentTerm;
use App\Support\InAppNotifier;
use App\Support\TeacherScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Student progress and analysis (TASK: Student Progress and Analysis PRD and
 * instructions; summary doc 05).
 *
 * Who sees what, on the server (PRD §3.1):
 *  - admins: every student;
 *  - class teachers: their class, every subject;
 *  - subject teachers: the students they teach — overview, assessments and
 *    attendance — but class-relative subject detail only for their subjects;
 *  - students: their own released results, without class comparisons unless
 *    the school allows position;
 *  - nobody edits an academic record from here.
 */
class ProgressController extends Controller
{
    public function __construct(private ProgressService $progress)
    {
    }

    // ==================================================================
    // The student page
    // ==================================================================

    public function show(Request $request, int $id): JsonResponse
    {
        [$student, $scope] = $this->access($request, $id);
        $request->validate(['term_id' => 'nullable|integer']);
        $settings = ProgressSetting::for((int) $student->school_id);
        $user = $request->user();

        $summary = $this->progress->summary($student, $request->integer('term_id') ?: null, $scope);
        $insights = $this->progress->insights($student);
        $seesFlags = $user->userProfile?->role === 'school_admin' || $settings->teachers_see_flags;

        return response()->json([
            'student' => $this->header($student),
            'is_class_teacher' => $scope === null,
            'subject_scope' => $scope,
        ] + $summary + [
            'insights' => $insights['insights'],
            'flags' => $seesFlags ? $insights['flags'] : [],
            'flags_total' => $seesFlags ? $insights['flags_total'] : 0,
            'insights_label' => $insights['label'],
            'completion' => $this->progress->completion($student),
            'remarks' => $this->remarkRows($student, staff: true),
            'interventions' => Intervention::where('student_id', $student->id)->with('teacher:id,name')->orderByDesc('id')->get()
                ->map(fn ($i) => $this->interventionRow($i)),
            'explanations' => $this->explanations($settings),
        ]);
    }

    /** A student's own progress: released terms only (summary doc 05). */
    public function mine(Request $request): JsonResponse
    {
        $student = Student::where('user_id', $request->user()->id)->with('user:id,name', 'currentClass:id,name')->firstOrFail();
        $settings = ProgressSetting::for((int) $student->school_id);

        $summary = $this->progress->summary($student, null, null, publishedOnly: true);

        // No class comparisons for students, and position only if allowed (§2).
        if ($summary['overview']) {
            if (! $settings->students_see_position) {
                $summary['overview']['position'] = null;
                $summary['overview']['class_size'] = null;
            }
        }
        $summary['subjects'] = collect($summary['subjects'])->map(fn ($s) => array_merge($s, ['class_average' => null, 'gap' => null, 'position' => $settings->students_see_position ? $s['position'] : null]))->all();
        $summary['trend'] = collect($summary['trend'])->map(fn ($p) => array_merge($p, ['class_average' => null]))->all();

        return response()->json([
            'student' => $this->header($student),
        ] + $summary + [
            'completion' => $this->progress->completion($student),
            'attendance' => $this->progress->attendance($student, CurrentTerm::for((int) $student->school_id)?->id),
            'remarks' => $this->remarkRows($student, staff: false),
            'interventions' => $settings->students_see_interventions
                ? Intervention::where('student_id', $student->id)->whereIn('status', ['planned', 'ongoing', 'completed'])->get()
                    ->map(fn ($i) => ['subject' => $i->subject_id ? DB::table('subjects')->where('id', $i->subject_id)->value('name') : null, 'summary' => $i->plain_summary ?? "Goal: {$i->target}", 'review_date' => $i->review_date?->toDateString(), 'status' => $i->status])
                : [],
            'explanations' => $this->explanations($settings),
        ]);
    }

    public function subject(Request $request, int $id, int $subjectId): JsonResponse
    {
        [$student, $scope] = $this->access($request, $id);
        $name = (string) DB::table('subjects')->where('school_id', $student->school_id)->where('id', $subjectId)->value('name');
        abort_if($name === '', 404);

        $detail = $scope === null || in_array($subjectId, $scope, true);

        return response()->json([
            'subject' => ['id' => $subjectId, 'name' => $name],
            'history' => $this->progress->subjectHistory($student, $name),
            'topics' => $detail ? $this->progress->topics($student, $subjectId) : [],
            'assessments' => $this->progress->assessments($student, $subjectId)['history'],
            'remarks' => collect($this->remarkRows($student, staff: true))->where('subject_id', $subjectId)->values(),
            'interventions' => Intervention::where('student_id', $student->id)->where('subject_id', $subjectId)->get()->map(fn ($i) => $this->interventionRow($i)),
        ]);
    }

    public function topics(Request $request, int $id): JsonResponse
    {
        [$student, $scope] = $this->access($request, $id);
        $topics = collect($this->progress->topics($student, $request->integer('subject_id') ?: null))
            ->filter(fn ($t) => $scope === null || in_array($t['subject_id'], $scope, true))->values();

        return response()->json(['data' => $topics, 'thresholds' => ProgressSetting::for((int) $student->school_id)->only(['topic_strong', 'topic_developing', 'topic_min_questions'])]);
    }

    public function assessments(Request $request, int $id): JsonResponse
    {
        [$student] = $this->access($request, $id);
        $request->validate(['subject_id' => 'nullable|integer', 'from' => 'nullable|date', 'to' => 'nullable|date', 'kind' => 'nullable|in:cbt,assignment']);

        $result = $this->progress->assessments($student, $request->integer('subject_id') ?: null, $request->input('from'), $request->input('to'));

        if ($request->filled('kind')) {
            $result['history'] = $result['history']->where('kind', $request->input('kind'))->values();
        }

        return response()->json($result);
    }

    public function attendance(Request $request, int $id): JsonResponse
    {
        [$student] = $this->access($request, $id);

        return response()->json($this->progress->attendance($student, $request->integer('term_id') ?: null));
    }

    /** The detailed analysis report for staff (PRD §13), from snapshots and the calculator. */
    public function report(Request $request, int $id)
    {
        [$student, $scope] = $this->access($request, $id);
        $summary = $this->progress->summary($student, $request->integer('term_id') ?: null, $scope);
        $insights = $this->progress->insights($student);
        $attendance = $this->progress->attendance($student);
        $school = School::find($student->school_id);
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $o = $summary['overview'] ?? [];
        $rows = collect($summary['subjects'])->map(fn ($s) => '<tr><td>'.$e($s['subject']).'</td><td>'.$e($s['ca']).'</td><td>'.$e($s['exam']).'</td><td>'.$e($s['total']).'</td><td>'.$e($s['grade']).'</td><td>'.$e($s['class_average']).'</td><td>'.$e($s['previous_total']).'</td><td>'.$e($s['change'] === null ? '' : ($s['change'] > 0 ? '+' : '').$s['change']).'</td></tr>')->implode('');
        $trend = collect($summary['trend'])->map(fn ($p) => '<tr><td>'.$e($p['label']).($p['provisional'] ? ' (provisional)' : '').'</td><td>'.$e($p['average']).'</td><td>'.$e($p['class_average']).'</td></tr>')->implode('');
        $ins = collect($insights['insights'])->concat($insights['flags'])->map(fn ($i) => '<li>'.$e($i['text']).'</li>')->implode('');
        $remarks = collect($this->remarkRows($student, staff: true))->map(fn ($r) => '<li>'.$e($r['teacher']).' ('.$e($r['created_at']).'): '.$e($r['body']).'</li>')->implode('');
        $plans = Intervention::where('student_id', $student->id)->get()->map(fn ($i) => '<li>'.$e($i->issue).' — baseline '.$e($i->baseline).', target '.$e($i->target).', '.$e($i->status).($i->outcome ? ', outcome: '.$e($i->outcome) : '').'</li>')->implode('');

        $html = '<!doctype html><html><head><meta charset="utf-8"><style>@page{margin:14mm}body{font-family:DejaVu Sans,sans-serif;font-size:9.5px}h1{font-size:15px;margin:0}h2{font-size:11.5px;margin:10px 0 4px}table{width:100%;border-collapse:collapse}th,td{border:.5px solid #777;padding:2px 4px}th{background:#eee}.s{font-size:8px;color:#555}</style></head><body>'
            .'<h1>'.$e($school?->name).'</h1><p>Academic progress report · '.$e($student->user?->name).' · '.$e($student->admission_number).' · '.$e($summary['period']['label'] ?? '').($summary['period']['provisional'] ?? false ? ' (provisional)' : '').'</p>'
            .'<h2>Overview</h2><p>Average '.$e($o['average'] ?? '—').' · previous '.$e($o['previous_average'] ?? '—').' · change '.$e($o['change_points'] ?? '—').' points · subjects passed '.$e(($o['subjects_passed'] ?? 0).' of '.($o['subjects_counted'] ?? 0)).' · attendance '.$e($attendance['totals']['rate'] ?? '—').'%</p>'
            .'<h2>Subjects</h2><table><tr><th>Subject</th><th>CA</th><th>Exam</th><th>Total</th><th>Grade</th><th>Class avg</th><th>Previous</th><th>Change</th></tr>'.$rows.'</table>'
            .'<h2>Term by term</h2><table><tr><th>Term</th><th>Average</th><th>Class average</th></tr>'.$trend.'</table>'
            .'<h2>Automated observations</h2><ul>'.$ins.'</ul><p class="s">'.$e($insights['label']).'</p>'
            .'<h2>Teacher remarks</h2><ul>'.$remarks.'</ul>'
            .'<h2>Intervention plans</h2><ul>'.$plans.'</ul>'
            .'<p class="s">Generated '.$e(now()->format('j M Y H:i')).'. Changes are in percentage points. ____________________ Principal</p></body></html>';

        ProgressReportExport::create([
            'school_id' => $student->school_id, 'student_id' => $student->id, 'term_id' => $summary['period']['term_id'] ?? null,
            'user_id' => $request->user()->id, 'kind' => 'detailed', 'data_hash' => hash('sha256', $html),
        ]);
        AuditLog::create(['school_id' => $student->school_id, 'user_id' => $request->user()->id, 'action' => 'progress.report_downloaded', 'auditable_type' => Student::class, 'auditable_id' => $student->id]);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)->setPaper('a4')->setOptions(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false])->output();

        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="progress-'.$student->id.'.pdf"', 'Cache-Control' => 'no-store']);
    }

    // ==================================================================
    // Remarks (§5.10)
    // ==================================================================

    public function storeRemark(Request $request, int $id): JsonResponse
    {
        [$student, $scope] = $this->access($request, $id);
        $data = $request->validate([
            'body' => 'required|string|min:3|max:2000',
            'subject_id' => ['nullable', Rule::exists('subjects', 'id')->where('school_id', $student->school_id)],
            'category' => ['nullable', Rule::in(array_keys(StudentRemark::CATEGORIES))],
            'visibility' => 'nullable|in:staff_only,student_visible',
        ]);

        if (! empty($data['subject_id']) && $scope !== null && ! in_array((int) $data['subject_id'], $scope, true)) {
            return response()->json(['message' => 'You can add subject remarks only for subjects you teach.'], 403);
        }

        $remark = StudentRemark::create($data + [
            'school_id' => $student->school_id,
            'student_id' => $student->id,
            'teacher_id' => $request->user()->id,
            'term_id' => CurrentTerm::for((int) $student->school_id)?->id,
            'category' => $data['category'] ?? 'academic',
            'visibility' => $data['visibility'] ?? 'staff_only',
        ]);

        $this->audit($request, 'progress.remark_added', $remark->id, StudentRemark::class, null, $remark->toArray());

        return response()->json(['remark' => $remark], 201);
    }

    public function updateRemark(Request $request, int $remarkId): JsonResponse
    {
        $remark = StudentRemark::where('school_id', $this->schoolId())->findOrFail($remarkId);
        $this->access($request, $remark->student_id);
        abort_unless($remark->teacher_id === $request->user()->id || $request->user()->userProfile?->role === 'school_admin', 403, 'Only the author or an admin can edit a remark.');

        $data = $request->validate(['body' => 'sometimes|string|min:3|max:2000', 'visibility' => 'sometimes|in:staff_only,student_visible']);
        $old = $remark->toArray();
        $remark->update($data);
        $this->audit($request, 'progress.remark_edited', $remark->id, StudentRemark::class, $old, $remark->toArray());

        return response()->json(['remark' => $remark->fresh()]);
    }

    public function deleteRemark(Request $request, int $remarkId): JsonResponse
    {
        $remark = StudentRemark::where('school_id', $this->schoolId())->findOrFail($remarkId);
        abort_unless($remark->teacher_id === $request->user()->id || $request->user()->userProfile?->role === 'school_admin', 403);

        $remark->delete(); // soft delete (§3 rule 11)
        $this->audit($request, 'progress.remark_removed', $remark->id, StudentRemark::class, $remark->toArray(), null);

        return response()->json(['message' => 'Removed.']);
    }

    // ==================================================================
    // Interventions (§7, PRD §12.2)
    // ==================================================================

    public function storeIntervention(Request $request, int $id): JsonResponse
    {
        [$student, $scope] = $this->access($request, $id);
        $schoolId = (int) $student->school_id;

        $data = $request->validate([
            'subject_id' => ['nullable', Rule::exists('subjects', 'id')->where('school_id', $schoolId)],
            'topic' => 'nullable|string|max:120',
            'issue' => 'required|string|min:5|max:1000',
            'baseline' => 'required|string|max:120',
            'target' => 'required|string|max:120',
            'action' => 'required|string|min:5|max:2000',
            'responsible_teacher_id' => 'nullable|integer',
            'start_date' => 'required|date',
            'review_date' => 'required|date|after_or_equal:start_date',
            'plain_summary' => 'nullable|string|max:500',
        ]);

        if (! empty($data['subject_id']) && $scope !== null && ! in_array((int) $data['subject_id'], $scope, true)) {
            return response()->json(['message' => 'You can create plans only for subjects you teach.'], 403);
        }

        $isAdmin = $request->user()->userProfile?->role === 'school_admin';
        $teacherId = $data['responsible_teacher_id'] ?? $request->user()->id;

        // Only an admin assigns a plan to someone else (§8.4).
        if ($teacherId !== $request->user()->id && ! $isAdmin) {
            return response()->json(['message' => 'Only an admin can assign a plan to another teacher.'], 403);
        }

        abort_unless(DB::table('user_profiles')->where('school_id', $schoolId)->where('user_id', $teacherId)->whereIn('role', ['teacher', 'school_admin'])->exists(), 422, 'Choose a teacher at this school.');

        $plan = Intervention::create($data + [
            'school_id' => $schoolId,
            'student_id' => $student->id,
            'responsible_teacher_id' => $teacherId,
            'status' => 'planned',
            'created_by' => $request->user()->id,
            'assigned_by' => $teacherId !== $request->user()->id ? $request->user()->id : null,
        ]);

        $this->audit($request, 'progress.intervention_created', $plan->id, Intervention::class, null, $plan->toArray());

        if ($teacherId !== $request->user()->id) {
            InAppNotifier::send($schoolId, [$teacherId], 'intervention', 'Support plan assigned to you', "A support plan for a student is now yours to lead. Review it by {$plan->review_date->format('j M')}.");
        }

        return response()->json(['intervention' => $this->interventionRow($plan->fresh('teacher'))], 201);
    }

    public function updateIntervention(Request $request, int $interventionId): JsonResponse
    {
        $plan = Intervention::where('school_id', $this->schoolId())->findOrFail($interventionId);
        $this->access($request, $plan->student_id);
        $this->mayManage($request, $plan);

        $data = $request->validate([
            'status' => ['sometimes', Rule::in(Intervention::STATUSES)],
            'outcome' => 'sometimes|nullable|string|max:2000',
            'target' => 'sometimes|string|max:120',
            'action' => 'sometimes|string|max:2000',
            'review_date' => 'sometimes|date',
            'plain_summary' => 'sometimes|nullable|string|max:500',
        ]);

        if (($data['status'] ?? null) === 'completed' && empty($data['outcome']) && ! $plan->outcome) {
            return response()->json(['message' => 'Record the outcome before completing a plan.'], 422);
        }

        $old = $plan->toArray();
        $plan->update($data);
        $this->audit($request, 'progress.intervention_updated', $plan->id, Intervention::class, $old, $plan->toArray());

        return response()->json(['intervention' => $this->interventionRow($plan->fresh('teacher'))]);
    }

    public function reviewIntervention(Request $request, int $interventionId): JsonResponse
    {
        $plan = Intervention::where('school_id', $this->schoolId())->findOrFail($interventionId);
        $this->access($request, $plan->student_id);
        $this->mayManage($request, $plan);

        $data = $request->validate([
            'progress' => 'required|string|min:3|max:2000',
            'result' => 'nullable|string|max:120',
            'next_review_date' => 'nullable|date|after:today',
        ]);

        $review = InterventionReview::create($data + ['school_id' => $plan->school_id, 'intervention_id' => $plan->id, 'reviewed_by' => $request->user()->id]);

        $plan->update(['status' => $plan->status === 'planned' ? 'ongoing' : $plan->status, 'review_date' => $data['next_review_date'] ?? $plan->review_date]);
        $this->audit($request, 'progress.intervention_reviewed', $plan->id, Intervention::class, null, $review->toArray());

        return response()->json(['review' => $review, 'intervention' => $this->interventionRow($plan->fresh('teacher'))], 201);
    }

    /** Plans across the school or mine, with reviews due, and the outcome report (§7). */
    public function interventions(Request $request): JsonResponse
    {
        $request->validate(['status' => 'nullable|in:planned,ongoing,completed,cancelled', 'due' => 'nullable|boolean']);
        $user = $request->user();
        $isAdmin = $user->userProfile?->role === 'school_admin';

        $plans = Intervention::where('school_id', $this->schoolId())
            ->when(! $isAdmin, fn ($q) => $q->where('responsible_teacher_id', $user->id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->boolean('due'), fn ($q) => $q->whereIn('status', ['planned', 'ongoing'])->where('review_date', '<=', now()->addDays(7)->toDateString()))
            ->with('teacher:id,name', 'student.user:id,name')
            ->orderBy('review_date')
            ->limit(300)
            ->get();

        return response()->json([
            'data' => $plans->map(fn ($i) => $this->interventionRow($i) + ['student_name' => $i->student?->user?->name, 'student_id' => $i->student_id]),
            'outcomes' => $plans->where('status', 'completed')->map(fn ($i) => ['student' => $i->student?->user?->name, 'issue' => $i->issue, 'baseline' => $i->baseline, 'target' => $i->target, 'outcome' => $i->outcome])->values(),
        ]);
    }

    // ==================================================================
    // Class and school views
    // ==================================================================

    public function classAnalysis(Request $request, int $classId): JsonResponse
    {
        $schoolId = $this->schoolId();
        $class = SchoolClass::where('school_id', $schoolId)->findOrFail($classId);
        $request->validate(['term_id' => ['nullable', Rule::exists('terms', 'id')->where('school_id', $schoolId)]]);
        $user = $request->user();

        $scope = $this->classScope($user, $class);
        abort_if($scope === false, 403, 'You can analyse only the classes you teach.');

        $termId = $request->integer('term_id') ?: CurrentTerm::for($schoolId)?->id;
        abort_unless($termId, 422, 'Set up a term first.');

        return response()->json($this->progress->classAnalysis($schoolId, $termId, $classId, $scope));
    }

    public function schoolAnalytics(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $request->validate(['term_id' => ['nullable', Rule::exists('terms', 'id')->where('school_id', $schoolId)], 'top' => 'nullable|integer|min:3|max:20']);
        $termId = $request->integer('term_id') ?: CurrentTerm::for($schoolId)?->id;
        abort_unless($termId, 422, 'Set up a term first.');

        return response()->json($this->progress->schoolAnalytics($schoolId, $termId, $request->integer('top') ?: 5));
    }

    public function settings(): JsonResponse
    {
        $s = ProgressSetting::for($this->schoolId());

        return response()->json($s->toArray() + ['defaults' => ProgressSetting::DEFAULTS]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'trend_points' => 'sometimes|integer|min:1|max:50',
            'steady_range' => 'sometimes|integer|min:1|max:50',
            'sharp_decline_points' => 'sometimes|integer|min:1|max:50',
            'low_attendance_rate' => 'sometimes|integer|min:1|max:100',
            'low_completion_rate' => 'sometimes|integer|min:1|max:100',
            'multiple_failed_subjects' => 'sometimes|integer|min:1|max:20',
            'topic_strong' => 'sometimes|integer|min:1|max:100',
            'topic_developing' => 'sometimes|integer|min:1|max:100|lt:topic_strong',
            'topic_min_questions' => 'sometimes|integer|min:2|max:50',
            'core_subject_ids' => 'sometimes|nullable|array',
            'core_subject_ids.*' => 'integer',
            'max_flags_shown' => 'sometimes|integer|min:1|max:20',
            'teachers_see_flags' => 'sometimes|boolean',
            'students_see_position' => 'sometimes|boolean',
            'students_see_interventions' => 'sometimes|boolean',
        ]);

        $s = ProgressSetting::withoutGlobalScopes()->firstOrNew(['school_id' => $this->schoolId()]);
        $old = $s->exists ? $s->toArray() : null;
        $s->fill($data)->save();
        $this->audit($request, 'progress.settings_updated', $s->id, ProgressSetting::class, $old, $s->toArray());

        return $this->settings();
    }

    // ==================================================================
    // Access
    // ==================================================================

    /**
     * The student and the subject scope for this user: null means every
     * subject (admin, class teacher, or a teacher granted the whole school).
     * Students and parents never get here (route roles), and a student the
     * teacher does not teach is a 404.
     *
     * @return array{0: Student, 1: array<int, int>|null}
     */
    private function access(Request $request, int $studentId): array
    {
        $user = $request->user();
        $student = Student::where('school_id', $this->schoolId())->with('user:id,name', 'currentClass:id,name,level_category')->findOrFail($studentId);
        $role = $user->userProfile?->role;

        if (in_array($role, ['school_admin', 'super_admin'], true)) {
            return [$student, null];
        }

        abort_unless($role === 'teacher', 404);

        if (TeacherScope::seesWholeSchool($user)) {
            return [$student, null];
        }

        abort_unless(TeacherScope::teaches($user, $student), 404);

        $class = $student->currentClass;
        $scope = $class ? $this->classScope($user, $class) : [];

        // Teaches the student but no subject in the class: overview only.
        return [$student, $scope === false ? [] : $scope];
    }

    /** null = every subject; array = these subjects; false = no access. */
    private function classScope(User $user, SchoolClass $class): array|null|false
    {
        $role = $user->userProfile?->role;

        if (in_array($role, ['school_admin', 'super_admin'], true) || TeacherScope::seesWholeSchool($user)) {
            return null;
        }

        $sessionId = TeacherScope::currentSessionId((int) $class->school_id);
        $formTeacher = $sessionId && DB::table('class_teacher_assignments')->where('session_id', $sessionId)->where('class_id', $class->id)
            ->where(fn ($q) => $q->where('form_teacher_id', $user->id)->orWhere('assistant_teacher_id', $user->id))->exists();

        if ($formTeacher) {
            return null;
        }

        $subjects = app(\App\Services\Academics\TeachingAssignments::class)->rows((int) $this->schoolId(), $user->id)
            ->filter(fn ($r) => $r->class_id === null || $r->class_id === (int) $class->id)
            ->pluck('subject_id')->unique()->values()->all();

        return $subjects === [] ? false : $subjects;
    }

    private function mayManage(Request $request, Intervention $plan): void
    {
        $user = $request->user();
        abort_unless($plan->responsible_teacher_id === $user->id || $plan->created_by === $user->id || $user->userProfile?->role === 'school_admin', 403, 'Only the responsible teacher or an admin can change this plan.');
    }

    private function header(Student $student): array
    {
        $classTeacher = DB::table('class_teacher_assignments')->join('users', 'users.id', '=', 'class_teacher_assignments.form_teacher_id')
            ->where('class_teacher_assignments.class_id', $student->class_id)
            ->where('class_teacher_assignments.session_id', TeacherScope::currentSessionId((int) $student->school_id))
            ->value('users.name');

        return [
            'id' => $student->id,
            'name' => $student->user?->name,
            'admission_number' => $student->admission_number,
            'class' => $student->currentClass?->name,
            'class_id' => $student->class_id,
            'class_teacher' => $classTeacher,
            'status' => $student->status,
            'photo' => $student->passport_photo_path,
        ];
    }

    private function remarkRows(Student $student, bool $staff): array
    {
        return StudentRemark::where('student_id', $student->id)
            ->when(! $staff, fn ($q) => $q->where('visibility', 'student_visible'))
            ->with('teacher:id,name')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'teacher' => $r->teacher?->name,
                'teacher_id' => $r->teacher_id,
                'subject_id' => $r->subject_id,
                'subject' => $r->subject_id ? DB::table('subjects')->where('id', $r->subject_id)->value('name') : null,
                'category' => $r->category,
                'body' => $r->body,
                'visibility' => $staff ? $r->visibility : null,
                'created_at' => $r->created_at?->toDateString(),
                'label' => 'Teacher observation',
            ])->all();
    }

    private function interventionRow(Intervention $i): array
    {
        return [
            'id' => $i->id,
            'subject_id' => $i->subject_id,
            'subject' => $i->subject_id ? DB::table('subjects')->where('id', $i->subject_id)->value('name') : null,
            'topic' => $i->topic,
            'issue' => $i->issue,
            'baseline' => $i->baseline,
            'target' => $i->target,
            'action' => $i->action,
            'responsible_teacher' => $i->teacher?->name,
            'responsible_teacher_id' => $i->responsible_teacher_id,
            'start_date' => $i->start_date?->toDateString(),
            'review_date' => $i->review_date?->toDateString(),
            'review_due' => in_array($i->status, ['planned', 'ongoing'], true) && $i->review_date?->isPast(),
            'status' => $i->status,
            'outcome' => $i->outcome,
            'plain_summary' => $i->plain_summary,
            'reviews' => $i->reviews()->get(['progress', 'result', 'next_review_date', 'created_at']),
        ];
    }

    /** "How is this calculated?" notes beside each figure (§5.11). */
    private function explanations(ProgressSetting $s): array
    {
        return [
            'average' => 'The mean of the subjects you take that were scored, using the school\'s own CA and exam weights. Subjects not taken, and marks not yet entered, are not counted as zero.',
            'change' => 'The difference from the previous term, in percentage points (78.5 against 72.4 is +6.1 points).',
            'grade' => 'From the grading scale the school used for that term. A later change of scale never changes an earlier term.',
            'subjects_passed' => 'Subjects at or above the school\'s pass mark.',
            'attendance' => 'Days present or late, out of school days recorded.',
            'completion' => 'Assessments done out of those assigned. One counts as missed only after its deadline has passed.',
            'topics' => "From CBT questions tagged with a topic. A topic needs at least {$s->topic_min_questions} questions before it is rated; strong is {$s->topic_strong}% and above, developing from {$s->topic_developing}%.",
            'provisional' => 'This term is not yet closed. Its figures may still change before results are approved.',
        ];
    }

    private function audit(Request $request, string $action, int $id, string $type, ?array $old, ?array $new): void
    {
        AuditLog::create(['school_id' => $this->schoolId(), 'user_id' => $request->user()->id, 'action' => $action, 'auditable_type' => $type, 'auditable_id' => $id, 'old_values' => $old, 'new_values' => $new, 'ip_address' => $request->ip()]);
    }
}
