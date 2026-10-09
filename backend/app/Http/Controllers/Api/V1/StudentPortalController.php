<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\CbtAttempt;
use App\Models\CbtExam;
use App\Models\Homework;
use App\Models\HomeworkSubmission;
use App\Models\LiveClass;
use App\Models\ResultRelease;
use App\Models\School;
use App\Models\ScoreEntry;
use App\Http\Resources\StudentResource;
use App\Models\Student;
use App\Models\StudentClassHistory;
use App\Models\Subject;
use App\Models\Term;
use App\Services\Academics\SubjectOffering;
use App\Services\Academics\TeachingAssignments;
use App\Services\Lms\LmsService;
use App\Services\ResultPinService;
use App\Support\CurrentTerm;
use App\Support\SchoolFeatures;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The student's own portal (TASK 02, 06, 10): a home page, My Subjects and
 * My Results. It reads from the modules that own each record and applies
 * their rules, not new ones:
 *
 *  - results only for a released term, and only once unlocked where the
 *    school sells result PINs (the same gate as the result checker);
 *  - a teacher comment only once it is human-written or approved, never
 *    while an AI draft is pending;
 *  - a CBT score only where the exam shows results to candidates;
 *  - homework and materials only as set or shared for this student.
 */
class StudentPortalController extends Controller
{
    private const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    public function __construct(private LmsService $lms)
    {
    }

    public function dashboard(Request $request): JsonResponse
    {
        $student = $this->student($request);
        $term = CurrentTerm::for($student->school_id);
        $today = now()->format('l');

        $attendance = $term ? AttendanceRecord::where('student_id', $student->id)->where('term_id', $term->id)->get()->countBy('status') : collect();
        $marked = $attendance->sum();

        $homework = Homework::where('school_id', $student->school_id)->setFor($student)->where('due_date', '>=', now()->toDateString())->with('subject:id,name')->orderBy('due_date')->limit(20)->get();
        $handedIn = HomeworkSubmission::where('student_id', $student->id)->whereIn('homework_id', $homework->pluck('id'))->pluck('homework_id')->all();

        $school = School::find($student->school_id);
        $features = SchoolFeatures::aiFeatures($school);

        return response()->json([
            'student' => ['id' => $student->id, 'name' => $student->user?->name, 'class' => $student->currentClass?->name, 'arm' => $student->currentArm?->name, 'admission_number' => $student->admission_number],
            'term' => $term ? ['id' => $term->id, 'name' => $term->name] : null,
            'today' => ['day' => $today, 'periods' => $this->withCover($this->timetable($student)->where('day', $today)->values(), $student)],
            'attendance' => ['marked_days' => $marked, 'present' => ($attendance['present'] ?? 0) + ($attendance['late'] ?? 0), 'absent' => $attendance['absent'] ?? 0, 'late' => $attendance['late'] ?? 0,
                // Excused days count as school days, so they lower the rate; say so.
                'excused' => $attendance['excused'] ?? 0,
                'rate' => $marked ? round((($attendance['present'] ?? 0) + ($attendance['late'] ?? 0)) / $marked * 100, 1) : null],
            'subjects_count' => $this->subjectsOf($student)->count(),
            'assignments_due' => $homework->reject(fn ($h) => in_array($h->id, $handedIn, true))->take(5)->map(fn ($h) => ['id' => $h->id, 'title' => $h->title, 'subject' => $h->subject?->name, 'due_date' => $h->due_date->toDateString()])->values(),
            'upcoming_tests' => $this->exams($student)->filter(fn ($e) => ! $e->closes_at || $e->closes_at->isFuture())->take(5)
                ->map(fn ($e) => ['id' => $e->id, 'title' => $e->title, 'subject' => $e->subject?->name, 'opens_at' => $e->opens_at?->toIso8601String(), 'closes_at' => $e->closes_at?->toIso8601String()])->values(),
            'virtual_classes' => SchoolFeatures::moduleEnabled($school, 'live_classes') ? LiveClass::where('class_id', $student->class_id)->where(fn ($q) => $q->whereNull('arm_id')->orWhere('arm_id', $student->arm_id))
                ->whereIn('status', ['scheduled', 'live'])->where('scheduled_for', '>=', now()->subHour())->orderBy('scheduled_for')->limit(3)->get()
                ->map(fn ($c) => ['id' => $c->id, 'title' => $c->title, 'scheduled_for' => $c->scheduled_for->toIso8601String(), 'is_joinable' => $c->is_joinable]) : [],
            'new_materials' => $this->lms->visibleTo($student)->where('published_at', '>=', now()->subDays(14))->count(),
            'results' => $term ? ['term_id' => $term->id, 'released' => ResultRelease::isReleasedFor($student->school_id, $term->id, $student->class_id)] : null,
            'tutor_available' => (bool) ($school?->ai_enabled ?? true) && ($features['tutor'] ?? true),
            'unread_notifications' => DB::table('notification_logs')->where('user_id', $student->user_id)->where('channel', 'in_app')->whereNull('read_at')->count(),
        ]);
    }

    public function timetableWeek(Request $request): JsonResponse
    {
        $student = $this->student($request);

        $periods = $this->timetable($student);
        // Where each lesson is held, week to week (the class's own room unless moved).
        $venues = app(\App\Services\Timetable\VenueService::class)->namesFor(
            DB::table('timetable_entries')->whereIn('id', $periods->pluck('entry_id'))->get(['id', 'school_class_id', 'venue_id']),
            (int) $student->school_id,
        );
        $periods = $periods->map(fn ($p) => (object) array_merge((array) $p, ['venue' => $venues[$p->entry_id] ?? null]));

        return response()->json(['days' => $periods->groupBy('day')->sortBy(fn ($v, $day) => array_search($day, self::DAYS, true))]);
    }

    /** My Subjects (06): what the student is enrolled in, with what each subject holds for them. */
    public function subjects(Request $request): JsonResponse
    {
        $student = $this->student($request);
        $subjects = $this->subjectsOf($student);
        $teachers = $this->teachers($student, $subjects->pluck('id')->all());
        $periods = $this->timetable($student)->countBy('subject_id');
        $materials = $this->lms->visibleTo($student)->whereNotNull('subject_id')->get(['id', 'subject_id'])->countBy('subject_id');
        $homework = Homework::where('school_id', $student->school_id)->setFor($student)->where('due_date', '>=', now()->toDateString())->get(['id', 'subject_id']);
        $handedIn = HomeworkSubmission::where('student_id', $student->id)->whereIn('homework_id', $homework->pluck('id'))->pluck('homework_id')->all();
        $openWork = $homework->reject(fn ($h) => in_array($h->id, $handedIn, true))->countBy('subject_id');
        $tests = $this->exams($student)->filter(fn ($e) => ! $e->closes_at || $e->closes_at->isFuture())->countBy('subject_id');

        return response()->json(['data' => $subjects->map(fn ($s) => [
            'id' => $s->id, 'name' => $s->name, 'code' => $s->code, 'elective' => $s->elective ?? false,
            'teachers' => $teachers[$s->id] ?? [],
            'periods_per_week' => $periods[$s->id] ?? 0,
            'materials' => $materials[$s->id] ?? 0,
            'assignments_due' => $openWork[$s->id] ?? 0,
            'upcoming_tests' => $tests[$s->id] ?? 0,
        ])->values()]);
    }

    public function subject(Request $request, int $id): JsonResponse
    {
        $student = $this->student($request);
        // Only a subject the student takes; no browsing other subjects (06).
        $subject = $this->subjectsOf($student)->firstWhere('id', $id);
        abort_unless($subject, 404);

        $homework = Homework::where('school_id', $student->school_id)->setFor($student)->where('subject_id', $id)->where('due_date', '>=', now()->subDays(30)->toDateString())->orderBy('due_date')->get();
        $subs = HomeworkSubmission::where('student_id', $student->id)->whereIn('homework_id', $homework->pluck('id'))->get()->keyBy('homework_id');

        return response()->json([
            'subject' => ['id' => $subject->id, 'name' => $subject->name, 'code' => $subject->code],
            'teachers' => $this->teachers($student, [$id])[$id] ?? [],
            'timetable' => $this->timetable($student)->where('subject_id', $id)->values(),
            'materials' => $this->lms->visibleTo($student)->where('subject_id', $id)->orderByDesc('published_at')->limit(30)->get()
                ->map(fn ($m) => ['id' => $m->id, 'title' => $m->title, 'type' => $m->type, 'topic' => $m->topic, 'published_at' => $m->published_at?->toIso8601String()]),
            'assignments' => $homework->map(fn ($h) => ['id' => $h->id, 'title' => $h->title, 'due_date' => $h->due_date->toDateString(),
                'status' => $subs->get($h->id)?->status ?? ($h->due_date->lt(now()->startOfDay()) ? 'missed' : 'not_submitted'),
                'marks_awarded' => $subs->get($h->id)?->status === 'graded' ? (float) $subs->get($h->id)->marks_awarded : null]),
            'tests' => $this->exams($student)->where('subject_id', $id)->values()->map(fn ($e) => $this->examRow($e, $student)),
            'results' => $this->releasedTerms($student)->map(function ($term) use ($student, $id) {
                $row = ScoreEntry::where('student_id', $student->id)->where('term_id', $term->id)->where('subject_id', $id)->first();

                return $row && $this->unlocked($student, $term) ? ['term' => $term->name, 'total' => (float) $row->total_score, 'grade' => $row->grade] : null;
            })->filter()->values(),
        ]);
    }

    /**
     * My Results (10). Released terms only; where the school charges for
     * results the breakdown opens only with the same PIN access the result
     * checker uses, so the portal is not a way round it.
     */
    public function results(Request $request): JsonResponse
    {
        $student = $this->student($request);
        $terms = Term::where('school_id', $student->school_id)->orderByDesc('start_date')->get();

        if (! $request->filled('term_id')) {
            return response()->json(['terms' => $terms->map(fn ($t) => [
                'id' => $t->id, 'name' => $t->name, 'released' => ResultRelease::isReleasedFor($student->school_id, $t->id, $student->class_id),
            ])]);
        }

        $term = $terms->firstWhere('id', $request->integer('term_id'));
        abort_unless($term, 404);
        if (! ResultRelease::isReleasedFor($student->school_id, $term->id, $student->class_id)) {
            return response()->json(['status' => 'not_released', 'message' => 'Results for this term have not been released yet.']);
        }

        $scores = ScoreEntry::where('student_id', $student->id)->where('term_id', $term->id)->with('subject:id,name')->get();
        if ($scores->isEmpty()) {
            return response()->json(['status' => 'no_scores', 'message' => 'No scores were recorded for you this term. Ask your class teacher.']);
        }
        if (! $this->unlocked($student, $term)) {
            return response()->json(['status' => 'locked', 'message' => 'Your school releases full results with a result-checker PIN. Ask your parent or guardian.',
                'preview' => ['subjects' => $scores->count(), 'average' => round((float) $scores->avg('total_score'), 2)]]);
        }

        return response()->json([
            'status' => 'released',
            'term' => ['id' => $term->id, 'name' => $term->name],
            'average' => round((float) $scores->where('result_state', '!=', 'exempt')->avg('total_score'), 2),
            'subjects' => $scores->sortBy(fn ($s) => $s->subject?->name)->values()->map(fn ($s) => [
                'subject' => $s->subject?->name,
                'state' => $s->result_state ?: 'scored',
                'components' => $s->component_scores ?: array_filter(['first_ca' => $s->first_ca, 'second_ca' => $s->second_ca, 'exam' => $s->exam], fn ($v) => $v !== null),
                'total' => $s->total_score !== null ? (float) $s->total_score : null,
                'grade' => $s->grade,
                'position' => $s->position_in_class,
                // Never an unreviewed AI draft (CLAUDE.md).
                'comment' => in_array($s->ai_comment_status, ['pending_approval', 'rejected'], true) ? null : $s->teacher_comment,
            ]),
        ]);
    }

    /**
     * My profile (TASK 03, the student's own view). The roster's field list
     * via StudentResource, so nothing new is exposed and the health record
     * stays behind its audited endpoint. Guardians by name and relationship
     * only: their phone numbers are theirs, not the child's to read (§12).
     */
    public function profile(Request $request): JsonResponse
    {
        $student = $this->student($request);
        $student->loadMissing('user:id,name,email');
        $account = $request->user()->userProfile;
        $term = CurrentTerm::for($student->school_id);
        $attendance = $term ? AttendanceRecord::where('student_id', $student->id)->where('term_id', $term->id)->get()->countBy('status') : collect();

        $guardians = $student->guardians()->with('user:id,name')->get()->map(fn ($g) => [
            'name' => $g->user?->name,
            'relationship' => $g->relationship,
        ])->values();

        $history = StudentClassHistory::where('student_id', $student->id)
            ->with(['fromClass:id,name', 'toClass:id,name', 'fromArm:id,name', 'toArm:id,name', 'session:id,name'])
            ->orderByDesc('created_at')->get()
            ->map(fn ($h) => [
                'date' => $h->created_at?->toDateString(),
                'action' => $h->action,
                'from' => trim(($h->fromClass?->name ?? '').' '.($h->fromArm?->name ?? '')) ?: null,
                'to' => trim(($h->toClass?->name ?? '').' '.($h->toArm?->name ?? '')) ?: null,
                'session' => $h->session?->name,
            ])->values();

        return response()->json([
            'student' => (new StudentResource($student))->resolve($request),
            'account' => ['phone' => $account?->phone, 'avatar_url' => $account?->avatar_url],
            'guardians' => $guardians,
            'class_history' => $history,
            'term' => $term ? ['id' => $term->id, 'name' => $term->name] : null,
            'attendance' => [
                'present' => $attendance['present'] ?? 0, 'late' => $attendance['late'] ?? 0,
                'absent' => $attendance['absent'] ?? 0, 'excused' => $attendance['excused'] ?? 0,
                'marked_days' => $attendance->sum(),
            ],
        ]);
    }

    /**
     * My tests (TASK 09): papers set for my class, upcoming, open and past,
     * each with my attempts. `/cbt/available` answers "what can I sit now";
     * this answers "where is the result of the test I sat last week". A score
     * appears only where the exam shows results to candidates.
     */
    public function tests(Request $request): JsonResponse
    {
        $student = $this->student($request);
        $exams = $this->exams($student);
        $attempts = CbtAttempt::where('student_id', $student->id)->whereIn('exam_id', $exams->pluck('id'))->orderBy('attempt_number')->get()->groupBy('exam_id');

        return response()->json(['data' => $exams->map(fn (CbtExam $e) => [
            'exam_id' => $e->id,
            'title' => $e->title,
            'subject' => $e->subject?->name,
            'duration_minutes' => $e->duration_minutes,
            'opens_at' => $e->opens_at?->toIso8601String(),
            'closes_at' => $e->closes_at?->toIso8601String(),
            'state' => $e->opens_at?->isFuture() ? 'upcoming' : ($e->closes_at?->isPast() ? 'closed' : 'open'),
            'attempts' => $attempts->get($e->id, collect())->map(fn (CbtAttempt $a) => [
                'attempt_id' => $a->id,
                'attempt_number' => $a->attempt_number,
                'status' => $a->status,
                'submitted_at' => $a->submitted_at?->toIso8601String(),
                'percentage' => $e->show_results_immediately && $a->percentage !== null && $a->isFinalised() ? (float) $a->percentage : null,
            ])->values(),
        ])->values()]);
    }

    // ==================================================================

    private function student(Request $request): Student
    {
        return Student::where('user_id', $request->user()->id)->with('user:id,name', 'currentClass:id,name', 'currentArm:id,name')->firstOrFail();
    }

    /** Enrolment first; the class's compulsory subjects if the school does not enrol individually. */
    private function subjectsOf(Student $student): Collection
    {
        $term = CurrentTerm::for($student->school_id);
        $enrolled = DB::table('student_subjects')->where('student_id', $student->id)->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('term_id')->when($term, fn ($w) => $w->orWhere('term_id', $term->id)))->get(['subject_id', 'enrollment_type']);

        if ($enrolled->isNotEmpty()) {
            $types = $enrolled->pluck('enrollment_type', 'subject_id');

            return Subject::whereIn('id', $enrolled->pluck('subject_id'))->orderBy('name')->get()->each(fn ($s) => $s->elective = ($types[$s->id] ?? '') === 'elective');
        }

        // The class's compulsory subjects for the student's arm (its stream).
        $ids = collect(app(SubjectOffering::class)->compulsoryFor($student->school_id, (int) $student->class_id, $student->arm_id ? (int) $student->arm_id : null));
        if ($ids->isEmpty()) {
            $ids = DB::table('teacher_subjects')->where('school_id', $student->school_id)->where('class_id', $student->class_id)->pluck('subject_id');
        }

        return Subject::whereIn('id', $ids->unique())->orderBy('name')->get();
    }

    /** @return array<int, array<int, string>> subject id => teacher names */
    private function teachers(Student $student, array $subjectIds): array
    {
        if (! $student->class_id) {
            return [];
        }

        // Who teaches this student's own arm, class teachers included.
        $rows = app(TeachingAssignments::class)->rows((int) $student->school_id)
            ->filter(fn ($r) => in_array($r->subject_id, $subjectIds, true)
                && TeachingAssignments::covers($r, (int) $student->class_id, $student->arm_id ? (int) $student->arm_id : null, $r->subject_id));
        $names = DB::table('users')->whereIn('id', $rows->pluck('teacher_id')->all() ?: [0])->pluck('name', 'id');

        return $rows->groupBy('subject_id')->map(fn ($g) => $g->map(fn ($r) => $names[$r->teacher_id] ?? null)->filter()->unique()->values()->all())->all();
    }

    private function timetable(Student $student): Collection
    {
        return DB::table('timetable_entries as e')->join('timetable_versions as v', 'v.id', '=', 'e.timetable_version_id')
            ->leftJoin('users as u', 'u.id', '=', 'e.teacher_id')
            ->where('v.school_id', $student->school_id)->where('v.status', 'published')->where('e.school_class_id', $student->class_id)
            ->orderBy('e.start_time')->get(['e.id as entry_id', 'e.day_of_week as day', 'e.slot_name', 'e.start_time', 'e.end_time', 'e.subject_id', 'e.subject_name', 'e.is_break', 'u.name as teacher']);
    }

    /** Today's cover teachers, where a lesson's own teacher is away. */
    private function withCover(Collection $periods, Student $student): Collection
    {
        $venues = app(\App\Services\Timetable\VenueService::class)->namesFor(
            DB::table('timetable_entries')->whereIn('id', $periods->pluck('entry_id'))->get(['id', 'school_class_id', 'venue_id']),
            (int) $student->school_id, now()->toDateString(),
        );
        $periods = $periods->map(fn ($p) => (object) array_merge((array) $p, ['venue' => $venues[$p->entry_id] ?? null]));

        $cover = DB::table('timetable_substitutions as s')->join('users as u', 'u.id', '=', 's.substitute_teacher_id')
            ->where('s.school_id', $student->school_id)->whereDate('s.date', now()->toDateString())->where('s.status', 'covered')
            ->whereIn('s.entry_id', $periods->pluck('entry_id'))->pluck('u.name', 's.entry_id');

        return $periods->map(fn ($p) => isset($cover[$p->entry_id]) ? (object) array_merge((array) $p, ['cover_teacher' => $cover[$p->entry_id]]) : $p)->values();
    }

    private function exams(Student $student): Collection
    {
        if (! SchoolFeatures::moduleEnabled(School::find($student->school_id), 'cbt')) {
            return collect();
        }

        return CbtExam::where('school_id', $student->school_id)->where('status', 'published')
            ->where(fn ($q) => $q->whereNull('class_id')->orWhere('class_id', $student->class_id))
            ->with('subject:id,name')->orderBy('opens_at')->limit(50)->get();
    }

    private function examRow(CbtExam $exam, Student $student): array
    {
        $attempt = CbtAttempt::where('exam_id', $exam->id)->where('student_id', $student->id)->orderByDesc('id')->first();

        return ['id' => $exam->id, 'title' => $exam->title, 'opens_at' => $exam->opens_at?->toIso8601String(), 'closes_at' => $exam->closes_at?->toIso8601String(),
            'attempted' => (bool) $attempt, 'submitted' => in_array($attempt?->status, ['submitted', 'graded'], true),
            // Scores only where the exam shows them to candidates.
            'percentage' => $attempt && $exam->show_results_immediately && $attempt->percentage !== null ? (float) $attempt->percentage : null];
    }

    private function releasedTerms(Student $student): Collection
    {
        return Term::where('school_id', $student->school_id)->orderByDesc('start_date')->get()
            ->filter(fn ($t) => ResultRelease::isReleasedFor($student->school_id, $t->id, $student->class_id))->values();
    }

    private function unlocked(Student $student, Term $term): bool
    {
        $school = School::find($student->school_id);

        return ! ($school?->result_checker_enabled ?? false) || (bool) app(ResultPinService::class)->accessFor($student->school_id, $student->id, $term->id);
    }
}
