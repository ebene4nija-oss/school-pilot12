<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Homework;
use App\Models\HomeworkSubmission;
use App\Models\LearningMaterial;
use App\Models\LiveClass;
use App\Models\LiveClassParticipant;
use App\Models\LmsSetting;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\Lms\LmsService;
use App\Support\CurrentTerm;
use App\Support\Sections;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Learning at a glance (TASK 14 §8, §10): one student's learning record for
 * the student and their parents, and aggregates for teachers, section heads
 * and the owner. Aggregates stay aggregates; names appear only in the
 * "needs support" list, for staff responsible for those students.
 */
class LmsDashboardController extends Controller
{
    public function __construct(private LmsService $lms)
    {
    }

    public function student(Request $request): JsonResponse
    {
        $student = Student::where('user_id', $request->user()->id)->firstOrFail();

        return response()->json($this->record($student, true));
    }

    public function parent(Request $request): JsonResponse
    {
        $children = Student::whereHas('guardians', fn ($q) => $q->where('guardians.user_id', $request->user()->id))->with('user:id,name')->get();
        if ($request->filled('student_id')) {
            $children = $children->where('id', $request->integer('student_id'));
            abort_if($children->isEmpty(), 404);
        }
        $showMaterials = LmsSetting::for($this->schoolId())->parents_see_materials;

        return response()->json(['children' => $children->values()->map(fn ($s) => ['student_id' => $s->id, 'name' => $s->user?->name] + $this->record($s, $showMaterials))]);
    }

    /** One student's learning record (§8.1). */
    private function record(Student $student, bool $withMaterials): array
    {
        $settings = LmsSetting::for((int) $student->school_id);
        $classes = LiveClass::where('class_id', $student->class_id)->where(fn ($q) => $q->whereNull('arm_id')->orWhere('arm_id', $student->arm_id));

        $upcoming = (clone $classes)->whereIn('status', ['scheduled', 'live'])->where('scheduled_for', '>=', now()->subHours(3))->with('subject:id,name')
            ->orderBy('scheduled_for')->limit(10)->get()->filter(fn ($c) => $c->ends_at->isFuture())->values()
            ->map(fn ($c) => ['id' => $c->id, 'title' => $c->title, 'subject' => $c->subject?->name, 'scheduled_for' => $c->scheduled_for->toIso8601String(), 'duration_minutes' => $c->duration_minutes, 'is_joinable' => $c->is_joinable]);

        $past = (clone $classes)->where('status', '!=', 'cancelled')->where('scheduled_for', '>=', now()->subDays(30))->where('scheduled_for', '<=', now())->with('subject:id,name')->orderByDesc('scheduled_for')->limit(30)->get()
            ->filter(fn ($c) => $c->ends_at->isPast());
        $participants = LiveClassParticipant::where('student_id', $student->id)->whereIn('live_class_id', $past->pluck('id'))->get()->keyBy('live_class_id');
        $attendance = $past->map(fn ($c) => ['id' => $c->id, 'title' => $c->title, 'subject' => $c->subject?->name, 'scheduled_for' => $c->scheduled_for->toIso8601String(), 'status' => $this->lms->status($participants->get($c->id), $c, $settings)])->values();

        $homework = Homework::where('school_id', $student->school_id)->setFor($student)->where('due_date', '>=', now()->subDays(60)->toDateString())
            ->with('subject:id,name')->orderBy('due_date')->get();
        $subs = HomeworkSubmission::where('student_id', $student->id)->whereIn('homework_id', $homework->pluck('id'))->get()->keyBy('homework_id');
        $today = now()->startOfDay();
        $assignments = $homework->map(function ($h) use ($subs, $today) {
            $s = $subs->get($h->id);
            $state = match (true) {
                $s?->status === 'graded' => 'graded',
                $s?->status === 'returned' => 'returned',
                $s !== null => 'submitted',
                $h->due_date->lt($today) => 'missed',
                default => 'pending',
            };

            return ['id' => $h->id, 'title' => $h->title, 'subject' => $h->subject?->name, 'due_date' => $h->due_date->toDateString(), 'state' => $state,
                'marks_awarded' => $s?->marks_awarded !== null ? (float) $s->marks_awarded : null, 'marks_available' => $s?->marks_available !== null ? (float) $s->marks_available : null,
                'feedback' => $s?->teacher_feedback, 'return_note' => $state === 'returned' ? $s->return_note : null];
        });

        return [
            'upcoming_classes' => $upcoming,
            'materials' => $withMaterials ? $this->lms->visibleTo($student)->with('subject:id,name')->orderByDesc('published_at')->limit(10)->get()
                ->map(fn ($m) => ['id' => $m->id, 'title' => $m->title, 'type' => $m->type, 'subject' => $m->subject?->name, 'topic' => $m->topic, 'published_at' => $m->published_at?->toIso8601String()]) : null,
            'assignments' => [
                'counts' => $assignments->countBy('state'),
                'pending' => $assignments->whereIn('state', ['pending', 'returned', 'missed'])->values(),
                'submitted' => $assignments->where('state', 'submitted')->values(),
                'graded' => $assignments->where('state', 'graded')->sortByDesc('due_date')->take(10)->values(),
            ],
            'virtual_attendance' => ['counts' => $attendance->countBy('status'), 'recent' => $attendance->take(10)],
            // Live participation and independent study are kept apart (§12).
            'lessons_attended' => $attendance->whereIn('status', ['present', 'late', 'partial'])->count(),
        ];
    }

    /**
     * Aggregates for staff (§8.2–8.4). A teacher sees their own classes; a
     * section head their section; an admin the school, optionally by section.
     */
    public function overview(Request $request): JsonResponse
    {
        $user = $request->user();
        $schoolId = $this->schoolId();
        $role = $user->userProfile?->role;
        $headed = $this->lms->sectionsHeaded($user, $schoolId);
        $isHead = $headed === null || $headed !== [];
        $teacherOnly = $role === 'teacher' && ! $isHead && ! $this->lms->isAdmin($user);

        $section = $request->input('section');
        if ($section && $headed !== null && ! in_array($section, $headed, true) && ! $teacherOnly) {
            abort(403, 'You can only see the sections you head.');
        }
        $sections = $section ? [$section] : ($teacherOnly ? null : $headed);
        $classIds = SchoolClass::where('school_id', $schoolId)->get(['id', 'level_category'])
            ->filter(fn ($c) => $sections === null || in_array(Sections::sectionOf(Sections::levelGroupOf($c->level_category)), $sections, true))->pluck('id');
        if ($request->filled('class_id')) {
            $classIds = $classIds->intersect([$request->integer('class_id')])->values();
        }

        $term = $request->filled('term_id') ? \App\Models\Term::where('school_id', $schoolId)->find($request->integer('term_id')) : CurrentTerm::for($schoolId);
        $from = $term ? Carbon::parse($term->start_date)->startOfDay() : now()->subDays(30)->startOfDay();
        $to = $term ? Carbon::parse($term->end_date)->endOfDay() : now()->addDays(30)->endOfDay();

        $teacherFilter = $teacherOnly ? $user->id : ($request->filled('teacher_id') ? $request->integer('teacher_id') : null);
        $subjectFilter = $request->filled('subject_id') ? $request->integer('subject_id') : null;

        $lessons = LiveClass::where('school_id', $schoolId)->whereIn('class_id', $classIds)->whereBetween('scheduled_for', [$from, $to])->where('status', '!=', 'cancelled')
            ->when($teacherFilter, fn ($q) => $q->where('teacher_id', $teacherFilter))->when($subjectFilter, fn ($q) => $q->where('subject_id', $subjectFilter))->limit(1000)->get();
        $done = $lessons->filter(fn ($l) => $l->status === 'ended' || $l->ends_at->isPast());

        $settings = LmsSetting::for($schoolId);
        $participants = LiveClassParticipant::whereIn('live_class_id', $done->pluck('id'))->get()->groupBy('live_class_id');
        $expected = 0;
        $attended = 0;
        $absences = [];
        foreach ($done as $l) {
            $byStudent = $participants->get($l->id, collect())->keyBy('student_id');
            foreach ($this->lms->studentsOf($schoolId, $l->class_id, $l->arm_id) as $s) {
                $expected++;
                $status = $this->lms->status($byStudent->get($s->id), $l, $settings);
                if (in_array($status, ['present', 'late'], true)) {
                    $attended++;
                } elseif ($status === 'absent') {
                    $absences[$s->id] = ($absences[$s->id] ?? 0) + 1;
                }
            }
        }

        $homework = Homework::where('school_id', $schoolId)->whereIn('class_id', $classIds)->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'published'))
            ->whereBetween('due_date', [$from->toDateString(), $to->toDateString()])
            ->when($teacherFilter, fn ($q) => $q->where('teacher_id', $teacherFilter))->when($subjectFilter, fn ($q) => $q->where('subject_id', $subjectFilter))->limit(1000)->get();
        $dueSoFar = $homework->filter(fn ($h) => $h->due_date->lt(now()->startOfDay()));
        $subs = HomeworkSubmission::whereIn('homework_id', $homework->pluck('id'))->get()->groupBy('homework_id');
        $owed = 0;
        $handedIn = 0;
        $missed = [];
        foreach ($dueSoFar as $h) {
            $got = $subs->get($h->id, collect())->keyBy('student_id');
            foreach ($this->lms->studentsOf($schoolId, $h->class_id, $h->arm_id, $h->targetStudentIds()) as $s) {
                $owed++;
                if ($got->has($s->id)) {
                    $handedIn++;
                } else {
                    $missed[$s->id] = ($missed[$s->id] ?? 0) + 1;
                }
            }
        }
        $graded = $subs->flatten()->where('status', 'graded')->filter(fn ($s) => (float) $s->marks_available > 0);

        $materials = LearningMaterial::where('school_id', $schoolId)->where('status', 'published')->whereBetween('published_at', [$from, $to])
            ->where(fn ($q) => $q->whereIn('class_id', $classIds)->orWhereNull('class_id'))
            ->when($teacherFilter, fn ($q) => $q->where('uploaded_by', $teacherFilter))->when($subjectFilter, fn ($q) => $q->where('subject_id', $subjectFilter))->pluck('id');

        $support = collect(array_keys($missed + $absences))
            ->filter(fn ($id) => ($missed[$id] ?? 0) >= 2 || ($absences[$id] ?? 0) >= 2);
        $names = Student::whereIn('id', $support->all() ?: [0])->with('user:id,name', 'currentClass:id,name')->get()->keyBy('id');

        return response()->json([
            'scope' => ['sections' => $sections, 'teacher_only' => $teacherOnly, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'term' => $term?->name],
            'metrics' => [
                'virtual_lessons_scheduled' => $lessons->count(),
                'virtual_lessons_completed' => $done->count(),
                'virtual_attendance_rate' => $expected ? round($attended / $expected * 100, 1) : null,
                'assignments_set' => $homework->count(),
                'submission_rate' => $owed ? round($handedIn / $owed * 100, 1) : null,
                'students_with_missed_work' => count($missed),
                'average_assignment_score' => $graded->isNotEmpty() ? round($graded->avg(fn ($s) => $s->marks_awarded / $s->marks_available * 100), 1) : null,
                'materials_published' => $materials->count(),
                'students_opening_materials' => DB::table('learning_material_views')->whereIn('material_id', $materials->all() ?: [0])->whereNotNull('student_id')->distinct()->count('student_id'),
            ],
            'needs_support' => $support->map(fn ($id) => ['student_id' => $id, 'name' => $names[$id]?->user?->name, 'class' => $names[$id]?->currentClass?->name, 'missed_assignments' => $missed[$id] ?? 0, 'missed_virtual_classes' => $absences[$id] ?? 0])
                ->sortByDesc(fn ($r) => $r['missed_assignments'] + $r['missed_virtual_classes'])->take(50)->values(),
        ]);
    }
}
