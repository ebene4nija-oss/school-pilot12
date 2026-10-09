<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Arm;
use App\Models\ExamSitting;
use App\Models\ExamTimetable;
use App\Services\Academics\SubjectOffering;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Exam timetables.
 *
 * An exams officer builds a draft sitting by sitting — class, subject, date,
 * time, venue, invigilator — and publishes it when it is right. Two mistakes
 * are refused as they are made rather than discovered in the exam hall:
 *
 *  - a class (or arm) booked into two papers at once, and
 *  - one teacher invigilating two rooms at once.
 *
 * Both are checked across every timetable in the school, because a mock
 * running alongside the main exam clashes just as badly. Rooms are not
 * checked: several classes sitting in one hall is how exams are normally run.
 *
 * Drafts are for administrators. Everyone else sees published timetables only.
 */
class ExamTimetableController extends Controller
{
    public function index(Request $request)
    {
        $schoolId = $this->schoolId();

        $timetables = ExamTimetable::where('school_id', $schoolId)
            ->when(! $this->isAdmin($request), fn ($q) => $q->where('status', 'published'))
            ->when($request->filled('term_id'), fn ($q) => $q->where('term_id', $request->term_id))
            ->with('term:id,name')
            ->withCount('sittings')
            ->withMin('sittings', 'exam_date')
            ->withMax('sittings', 'exam_date')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $timetables->map(fn (ExamTimetable $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'term_id' => $t->term_id,
                'term' => $t->term?->name,
                'status' => $t->status,
                'published_at' => $t->published_at?->toIso8601String(),
                'sittings' => $t->sittings_count,
                'starts_on' => $t->sittings_min_exam_date ? substr((string) $t->sittings_min_exam_date, 0, 10) : null,
                'ends_on' => $t->sittings_max_exam_date ? substr((string) $t->sittings_max_exam_date, 0, 10) : null,
            ]),
        ]);
    }

    public function store(Request $request)
    {
        $schoolId = $this->schoolId();

        if ($schoolId === null) {
            return response()->json(['message' => 'Choose a school first.'], 409);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:120',
            'term_id' => ['required', Rule::exists('terms', 'id')->where('school_id', $schoolId)],
        ]);

        $timetable = ExamTimetable::create($validated + ['school_id' => $schoolId, 'status' => 'draft']);

        return response()->json(['message' => 'Exam timetable created.', 'data' => $timetable], 201);
    }

    /** One timetable with its sittings, in date order. `class_id` and `mine=1` narrow it. */
    public function show(Request $request, $id)
    {
        $timetable = $this->find($request, $id);

        if (! $timetable) {
            return response()->json(['message' => 'Exam timetable not found.'], 404);
        }

        $sittings = ExamSitting::where('exam_timetable_id', $timetable->id)
            ->when($request->filled('class_id'), fn ($q) => $q->where('class_id', $request->class_id))
            ->when($request->boolean('mine'), fn ($q) => $q->where('invigilator_id', $request->user()->id))
            ->with(['schoolClass:id,name', 'arm:id,name', 'subject:id,name,code', 'invigilator:id,name', 'hall:id,name,capacity'])
            ->orderBy('exam_date')
            ->orderBy('start_time')
            ->get();

        // A student, or a parent asking for one child: only the papers that
        // child writes — their class and arm, subjects they take.
        if ($student = $this->candidateFor($request)) {
            $sittings = $sittings->filter(fn (ExamSitting $s) => $this->writes($student, $s))->values();
        }

        return response()->json([
            'data' => [
                'id' => $timetable->id,
                'name' => $timetable->name,
                'term_id' => $timetable->term_id,
                'status' => $timetable->status,
                'published_at' => $timetable->published_at?->toIso8601String(),
            ],
            'sittings' => $sittings->map(fn (ExamSitting $s) => $this->sittingRow($s, $this->isAdmin($request))),
        ]);
    }

    /**
     * Who writes a paper, arm by arm: the students of the class (or arm) who
     * take the subject, so SS 2 Physics is the science students only.
     */
    public function candidates(Request $request, $id)
    {
        $sitting = ExamSitting::where('school_id', $this->schoolId())->with(['schoolClass:id,name', 'subject:id,name', 'hall:id,name,capacity'])->find($id);

        if (! $sitting) {
            return response()->json(['message' => 'Paper not found.'], 404);
        }

        $students = $this->candidateRows($sitting);
        $arms = DB::table('arms')->whereIn('id', $students->pluck('arm_id')->filter()->all() ?: [0])->pluck('name', 'id');

        return response()->json([
            'sitting' => $this->sittingRow($sitting, true),
            'total' => $students->count(),
            'by_arm' => $students->groupBy(fn ($s) => $s->arm_id ?? 0)->map(fn ($g, $armId) => [
                'arm_id' => $armId ?: null,
                'arm' => $armId ? ($arms[$armId] ?? null) : null,
                'count' => $g->count(),
                'students' => $g->map(fn ($s) => ['id' => (int) $s->id, 'name' => $s->name, 'admission_number' => $s->admission_number])->sortBy('name')->values(),
            ])->values(),
        ]);
    }

    /** Rename, publish or unpublish. */
    public function update(Request $request, $id)
    {
        $timetable = $this->find($request, $id);

        if (! $timetable) {
            return response()->json(['message' => 'Exam timetable not found.'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:120',
            'status' => 'sometimes|required|in:draft,published',
        ]);

        if (($validated['status'] ?? null) === 'published' && $timetable->sittings()->count() === 0) {
            return response()->json(['message' => 'Add at least one paper before publishing.'], 422);
        }

        if (isset($validated['status']) && $validated['status'] !== $timetable->status) {
            $validated['published_at'] = $validated['status'] === 'published' ? now() : null;
        }

        $timetable->update($validated);

        return response()->json([
            'message' => $timetable->status === 'published' ? 'Published. Staff, students and parents can now see it.' : 'Saved.',
            'data' => $timetable->fresh(),
        ]);
    }

    /** Only a draft can be deleted; a published timetable is unpublished first. */
    public function destroy(Request $request, $id)
    {
        $timetable = $this->find($request, $id);

        if (! $timetable) {
            return response()->json(['message' => 'Exam timetable not found.'], 404);
        }

        if ($timetable->status === 'published') {
            return response()->json(['message' => 'Unpublish this timetable before deleting it.'], 409);
        }

        $timetable->delete();

        return response()->json(['message' => 'Exam timetable deleted.']);
    }

    public function storeSitting(Request $request, $id)
    {
        $timetable = $this->find($request, $id);

        if (! $timetable) {
            return response()->json(['message' => 'Exam timetable not found.'], 404);
        }

        $data = $this->validateSitting($request, $timetable->school_id);

        if ($clash = $this->clash($data, $timetable->school_id)) {
            return response()->json(['message' => $clash, 'errors' => ['clash' => [$clash]]], 422);
        }

        $sitting = ExamSitting::create($data + ['school_id' => $timetable->school_id, 'exam_timetable_id' => $timetable->id]);

        return response()->json([
            'message' => trim('Paper added. '.$this->capacityNote($sitting)),
            'data' => $this->sittingRow($sitting->load(['schoolClass:id,name', 'arm:id,name', 'subject:id,name,code', 'invigilator:id,name', 'hall:id,name,capacity']), true),
        ], 201);
    }

    public function updateSitting(Request $request, $id)
    {
        $sitting = ExamSitting::where('school_id', $this->schoolId())->find($id);

        if (! $sitting) {
            return response()->json(['message' => 'Paper not found.'], 404);
        }

        $data = $this->validateSitting($request, $sitting->school_id);

        if ($clash = $this->clash($data, $sitting->school_id, $sitting->id)) {
            return response()->json(['message' => $clash, 'errors' => ['clash' => [$clash]]], 422);
        }

        $sitting->update($data);

        return response()->json([
            'message' => trim('Paper updated. '.$this->capacityNote($sitting)),
            'data' => $this->sittingRow($sitting->fresh()->load(['schoolClass:id,name', 'arm:id,name', 'subject:id,name,code', 'invigilator:id,name', 'hall:id,name,capacity']), true),
        ]);
    }

    public function destroySitting(Request $request, $id)
    {
        $sitting = ExamSitting::where('school_id', $this->schoolId())->find($id);

        if (! $sitting) {
            return response()->json(['message' => 'Paper not found.'], 404);
        }

        $sitting->delete();

        return response()->json(['message' => 'Paper removed.']);
    }

    private function validateSitting(Request $request, int $schoolId): array
    {
        $validated = $request->validate([
            'class_id' => ['required', Rule::exists('classes', 'id')->where('school_id', $schoolId)],
            'arm_id' => ['nullable', Rule::exists('arms', 'id')->where('school_id', $schoolId)->where('class_id', $request->input('class_id'))],
            'subject_id' => ['required', Rule::exists('subjects', 'id')->where('school_id', $schoolId)],
            'paper' => 'nullable|string|max:60',
            'exam_date' => 'required|date_format:Y-m-d',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'venue' => 'nullable|string|max:80',
            // One hall for every candidate, or each arm in its own classroom.
            'venue_mode' => 'sometimes|in:hall,own_classrooms',
            'venue_id' => ['nullable', Rule::exists('venues', 'id')->where('school_id', $schoolId)],
            'invigilator_id' => ['nullable', Rule::exists('user_profiles', 'user_id')
                ->where('school_id', $schoolId)
                ->whereIn('role', ['teacher', 'school_admin'])],
        ], [
            'arm_id.exists' => 'That arm does not belong to the chosen class.',
            'end_time.after' => 'The paper must end after it starts.',
            'invigilator_id.exists' => 'The invigilator must be a member of staff at this school.',
        ]);

        // Stored with seconds so that string comparison against the time
        // column behaves the same on Postgres and SQLite.
        $validated['start_time'] .= ':00';
        $validated['end_time'] .= ':00';
        $validated['arm_id'] = $validated['arm_id'] ?? null;
        $validated['invigilator_id'] = $validated['invigilator_id'] ?? null;
        $validated['venue_mode'] = $validated['venue_mode'] ?? 'hall';

        // In their own classrooms there is no one hall to name.
        if ($validated['venue_mode'] === 'own_classrooms') {
            $validated['venue_id'] = null;
            $validated['venue'] = null;
        } elseif (! empty($validated['venue_id'])) {
            $validated['venue'] = DB::table('venues')->where('id', $validated['venue_id'])->value('name');
        }

        return $validated;
    }

    /**
     * A sentence describing the first clash, or null.
     *
     * Overlap is strict: one paper ending at 10:00 and the next starting at
     * 10:00 is back to back, not a clash.
     */
    private function clash(array $data, int $schoolId, ?int $ignoreId = null): ?string
    {
        $overlapping = fn () => ExamSitting::where('school_id', $schoolId)
            ->whereDate('exam_date', $data['exam_date'])
            ->where('start_time', '<', $data['end_time'])
            ->where('end_time', '>', $data['start_time'])
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->with(['subject:id,name', 'schoolClass:id,name', 'arm:id,name', 'invigilator:id,name']);

        // The same class, where either sitting is for the whole class or both
        // are for the same arm. JSS 2 Gold and JSS 2 Blue may sit different
        // papers at once; JSS 2 (all arms) and JSS 2 Gold may not.
        $classClash = $overlapping()
            ->where('class_id', $data['class_id'])
            ->when($data['arm_id'], fn ($q) => $q->where(fn ($w) => $w->whereNull('arm_id')->orWhere('arm_id', $data['arm_id'])))
            ->first();

        if ($classClash) {
            $who = trim($classClash->schoolClass?->name.' '.($classClash->arm?->name ?? ''));

            return "{$who} is already sitting {$classClash->subject?->name} from ".substr($classClash->start_time, 0, 5).' to '.substr($classClash->end_time, 0, 5).' that day.';
        }

        if ($data['invigilator_id']) {
            $busy = $overlapping()->where('invigilator_id', $data['invigilator_id'])->first();

            if ($busy) {
                $where = trim($busy->schoolClass?->name.' '.($busy->arm?->name ?? ''));

                return "{$busy->invigilator?->name} is already invigilating {$where} {$busy->subject?->name} from ".substr($busy->start_time, 0, 5).' to '.substr($busy->end_time, 0, 5).' that day.';
            }
        }

        return null;
    }

    private function find(Request $request, $id): ?ExamTimetable
    {
        return ExamTimetable::where('school_id', $this->schoolId())
            ->when(! $this->isAdmin($request), fn ($q) => $q->where('status', 'published'))
            ->find($id);
    }

    private function isAdmin(Request $request): bool
    {
        return in_array($request->user()?->userProfile?->role, ['school_admin', 'super_admin'], true);
    }

    private function sittingRow(ExamSitting $s, bool $withCandidates = false): array
    {
        $ownRooms = $s->venue_mode === 'own_classrooms';

        return [
            'id' => $s->id,
            'class_id' => $s->class_id,
            'class' => $s->schoolClass?->name,
            'arm_id' => $s->arm_id,
            'arm' => $s->arm?->name,
            'subject_id' => $s->subject_id,
            'subject' => $s->subject?->name,
            'subject_code' => $s->subject?->code,
            'paper' => $s->paper,
            'exam_date' => $s->exam_date?->format('Y-m-d'),
            'start_time' => substr((string) $s->start_time, 0, 5),
            'end_time' => substr((string) $s->end_time, 0, 5),
            'venue_mode' => $s->venue_mode ?? 'hall',
            'venue_id' => $s->venue_id,
            // What a candidate reads: the hall, or "Own classroom".
            'venue' => $ownRooms ? 'Own classroom' : ($s->hall?->name ?? $s->venue),
            'venue_capacity' => $ownRooms ? null : $s->hall?->capacity,
            'invigilator_id' => $s->invigilator_id,
            'invigilator' => $s->invigilator?->name,
        ] + ($withCandidates ? ['candidates' => $this->candidateRows($s)->count()] : []);
    }

    /** Students of the sitting's class (or arm) who take its subject. */
    private function candidateRows(ExamSitting $s): Collection
    {
        $offering = app(SubjectOffering::class);
        $rows = $offering->classRows($s->school_id, $s->class_id);
        $streams = $offering->armStreams($s->school_id);
        $chosen = DB::table('student_subjects')->where('school_id', $s->school_id)->where('subject_id', $s->subject_id)->where('status', 'active')->pluck('student_id')->flip();

        return DB::table('students')->leftJoin('users', 'users.id', '=', 'students.user_id')
            ->where('students.school_id', $s->school_id)->where('students.class_id', $s->class_id)
            ->where('students.status', 'active')->whereNull('students.deleted_at')
            ->when($s->arm_id, fn ($q) => $q->where('students.arm_id', $s->arm_id))
            ->get(['students.id', 'students.arm_id', 'students.admission_number', 'users.name'])
            ->filter(function ($student) use ($rows, $streams, $chosen, $s) {
                // A class with no subject list: everyone sits it.
                if ($rows->isEmpty()) {
                    return true;
                }

                $row = $rows->get($s->subject_id);
                $kind = $row ? SubjectOffering::kindFor($row, $student->arm_id ? ($streams[$student->arm_id] ?? null) : null) : null;

                return $kind === 'compulsory' || $chosen->has($student->id);
            })
            ->values();
    }

    /** Whether this student writes this paper. */
    private function writes(object $student, ExamSitting $s): bool
    {
        if ((int) $student->class_id !== (int) $s->class_id || ($s->arm_id && (int) $s->arm_id !== (int) $student->arm_id)) {
            return false;
        }

        return $this->candidateRows($s)->contains('id', $student->id);
    }

    /** The student a student or parent is asking about, or null for staff. */
    private function candidateFor(Request $request): ?object
    {
        $user = $request->user();
        $role = $user?->userProfile?->role;

        if ($role === 'student') {
            return DB::table('students')->where('user_id', $user->id)->where('school_id', $this->schoolId())->first(['id', 'class_id', 'arm_id']);
        }

        if ($role === 'parent' && $request->filled('student_id')) {
            $student = \App\Models\Student::where('school_id', $this->schoolId())->find($request->integer('student_id'));
            abort_unless($student && $user->can('view', $student), 404, 'Student not found.');

            return (object) ['id' => $student->id, 'class_id' => $student->class_id, 'arm_id' => $student->arm_id];
        }

        return null;
    }

    /** A warning when a hall has more candidates than seats at that time. */
    private function capacityNote(ExamSitting $s): string
    {
        $hall = $s->venue_id ? DB::table('venues')->where('id', $s->venue_id)->first(['name', 'capacity']) : null;

        if (! $hall?->capacity) {
            return '';
        }

        $together = ExamSitting::where('school_id', $s->school_id)->where('venue_id', $s->venue_id)
            ->whereDate('exam_date', $s->exam_date)
            ->where('start_time', '<', $s->end_time)->where('end_time', '>', $s->start_time)
            ->get()->sum(fn ($x) => $this->candidateRows($x)->count());

        return $together > $hall->capacity
            ? "{$hall->name} seats {$hall->capacity} but {$together} candidates write there at that time."
            : '';
    }
}
