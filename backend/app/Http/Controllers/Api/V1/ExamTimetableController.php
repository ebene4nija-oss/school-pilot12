<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Arm;
use App\Models\ExamSitting;
use App\Models\ExamTimetable;
use Illuminate\Http\Request;
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
            ->with(['schoolClass:id,name', 'arm:id,name', 'subject:id,name,code', 'invigilator:id,name'])
            ->orderBy('exam_date')
            ->orderBy('start_time')
            ->get();

        return response()->json([
            'data' => [
                'id' => $timetable->id,
                'name' => $timetable->name,
                'term_id' => $timetable->term_id,
                'status' => $timetable->status,
                'published_at' => $timetable->published_at?->toIso8601String(),
            ],
            'sittings' => $sittings->map(fn (ExamSitting $s) => $this->sittingRow($s)),
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
            'message' => 'Paper added.',
            'data' => $this->sittingRow($sitting->load(['schoolClass:id,name', 'arm:id,name', 'subject:id,name,code', 'invigilator:id,name'])),
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
            'message' => 'Paper updated.',
            'data' => $this->sittingRow($sitting->fresh()->load(['schoolClass:id,name', 'arm:id,name', 'subject:id,name,code', 'invigilator:id,name'])),
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

    private function sittingRow(ExamSitting $s): array
    {
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
            'venue' => $s->venue,
            'invigilator_id' => $s->invigilator_id,
            'invigilator' => $s->invigilator?->name,
        ];
    }
}
