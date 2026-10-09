<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use App\Models\TimetableEntry;
use App\Models\TimetableVersion;
use App\Services\Academics\TeachingAssignments;
use App\Services\TimetableSolverService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class TimetableController extends Controller
{
    /**
     * A standard secondary-school day: eight 40-minute periods, a short break
     * after the third and a long break after the sixth. Used when the caller
     * does not send its own bell schedule.
     */
    public const DEFAULT_SLOTS = [
        ['id' => 1, 'name' => 'Period 1', 'start_time' => '08:00', 'end_time' => '08:40'],
        ['id' => 2, 'name' => 'Period 2', 'start_time' => '08:40', 'end_time' => '09:20'],
        ['id' => 3, 'name' => 'Period 3', 'start_time' => '09:20', 'end_time' => '10:00'],
        ['id' => 4, 'name' => 'Short break', 'start_time' => '10:00', 'end_time' => '10:20', 'is_break' => true],
        ['id' => 5, 'name' => 'Period 4', 'start_time' => '10:20', 'end_time' => '11:00'],
        ['id' => 6, 'name' => 'Period 5', 'start_time' => '11:00', 'end_time' => '11:40'],
        ['id' => 7, 'name' => 'Period 6', 'start_time' => '11:40', 'end_time' => '12:20'],
        ['id' => 8, 'name' => 'Long break', 'start_time' => '12:20', 'end_time' => '13:00', 'is_break' => true],
        ['id' => 9, 'name' => 'Period 7', 'start_time' => '13:00', 'end_time' => '13:40'],
        ['id' => 10, 'name' => 'Period 8', 'start_time' => '13:40', 'end_time' => '14:20'],
    ];

    protected $solver;

    public function __construct(TimetableSolverService $solver)
    {
        $this->solver = $solver;
    }

    /**
     * Generate a draft timetable.
     *
     * Classes, teacher assignments and the bell schedule may all be sent
     * explicitly. Any that are left out come from the school's own records:
     * who teaches which subject to which class (subject teachers, and class
     * teachers where the section is taught by them), every class that has at
     * least one such teacher, and the standard day above.
     * The web builder sends nothing and relies on this.
     *
     * A class with no subject teachers is left out and named in `skipped`,
     * rather than failing the whole school: it has nothing to schedule, and
     * one unstaffed class should not stop every other class getting a
     * timetable. Every 422 carries a `message` a person can act on.
     */
    public function generate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'class_ids' => 'nullable|array',
            'assignments' => 'nullable|array',
            'slots' => 'nullable|array',
            'version_name' => 'nullable|string',
            'max_daily_teacher_workload' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $schoolId = $this->schoolId();

        $classIds = array_map('intval', $request->input('class_ids') ?: []);
        if ($classIds === []) {
            $classIds = SchoolClass::where('school_id', $schoolId)->orderBy('order_index')->pluck('id')->all();
        }

        if ($classIds === []) {
            return response()->json([
                'message' => 'This school has no classes yet. Create classes first, then generate a timetable.',
                'errors' => ['class_ids' => ['No classes found for this school.']],
            ], 422);
        }

        $assignments = $request->input('assignments') ?: $this->assignmentsFromRecords($schoolId, $classIds);

        $staffed = array_unique(array_map(fn ($a) => (int) $a['class_id'], $assignments));
        $skipped = array_values(array_diff($classIds, $staffed));
        $classIds = array_values(array_intersect($classIds, $staffed));

        if ($classIds === []) {
            return response()->json([
                'message' => 'No class has subject teachers assigned yet. Assign a teacher to each subject a class takes, then generate again.',
                'errors' => ['assignments' => ['No teacher–subject assignments found.']],
            ], 422);
        }

        $result = $this->solver->generateTimetable(
            $schoolId,
            $classIds,
            $assignments,
            $request->input('slots') ?: self::DEFAULT_SLOTS,
            [
                'version_name' => $request->input('version_name', 'Draft '.now()->format('j M Y, H:i')),
                'max_daily_teacher_workload' => $request->input('max_daily_teacher_workload', 6),
                'save_draft' => true,
            ]
        );

        if (! $result['success']) {
            $count = count($result['conflicts']);

            return response()->json([
                // The first clash in words, with the class named, is what a
                // client shows; the full list is there for anyone who wants it.
                'message' => $this->nameClasses($schoolId, $result['conflicts'][0])
                    .($count > 1 ? ' ('.($count - 1).' more.)' : '')
                    .' Assign more teachers, or raise the daily limit per teacher.',
                'conflicts' => $result['conflicts'],
            ], 422);
        }

        return response()->json([
            'message' => 'Conflict-free timetable generated and saved as draft.',
            'version' => $result['version'],
            'timetable' => $result['timetable'],
            'skipped' => SchoolClass::where('school_id', $schoolId)->whereIn('id', $skipped)->orderBy('order_index')->pluck('name'),
        ], 200);
    }

    public function versions(Request $request)
    {
        $schoolId = $this->schoolId();

        $versions = TimetableVersion::where('school_id', $schoolId)
            ->withCount('entries')
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        return response()->json([
            'versions' => $versions,
        ]);
    }

    public function publish(Request $request, $id)
    {
        $schoolId = $this->schoolId();

        $version = TimetableVersion::where('school_id', $schoolId)->where('id', $id)->firstOrFail();

        // A teacher in two classes at once is caught here, not on Monday morning (TASK 07).
        // Two classes in one lab at once likewise (TASK 07 venues).
        $clashes = array_merge(
            app(\App\Services\Timetable\CoverService::class)->clashes($version->id),
            app(\App\Services\Timetable\VenueService::class)->clashes($version->id, $schoolId),
        );
        if ($clashes !== []) {
            $first = $clashes[0];
            $what = ($first['type'] ?? 'teacher') === 'venue'
                ? "{$first['venue']} has {$first['classes'][0]} and {$first['classes'][1]}"
                : ($first['teacher'] ?? 'A teacher')." is in {$first['classes'][0]} and {$first['classes'][1]}";

            return response()->json([
                'message' => count($clashes)." clash(es): {$what} on {$first['day']} ({$first['slot']}). Fix the version before publishing.",
                'clashes' => $clashes,
            ], 422);
        }

        // Unpublish other versions for this school
        TimetableVersion::where('school_id', $schoolId)->update(['status' => 'draft']);

        $version->status = 'published';
        $version->published_at = now();
        $version->save();

        return response()->json([
            'message' => 'Timetable version published successfully.',
            'version' => $version,
        ]);
    }

    public function view(Request $request)
    {
        $schoolId = $this->schoolId();

        $versionId = $request->query('version_id');
        $classId = $request->query('class_id');
        // `mine=1`: the signed-in teacher's own lessons.
        $teacherId = $request->boolean('mine') ? $request->user()->id : $request->query('teacher_id');

        $query = TimetableEntry::whereHas('version', function ($q) use ($schoolId) {
            $q->where('school_id', $schoolId);
        });

        if ($versionId) {
            $query->where('timetable_version_id', $versionId);
        } else {
            // Default to published version if version_id is not specified
            $query->whereHas('version', function ($q) {
                $q->where('status', 'published');
            });
        }

        if ($classId) {
            $query->where('school_class_id', $classId);
        }

        if ($teacherId) {
            $query->where('teacher_id', $teacherId);
        }

        $entries = $query->orderBy('id')->get();

        /*
         * Names alongside the ids, so a client can print a timetable without
         * a lookup per cell. Added fields only: existing readers are unaffected.
         */
        $classNames = SchoolClass::where('school_id', $schoolId)
            ->whereIn('id', $entries->pluck('school_class_id')->unique())
            ->pluck('name', 'id');

        $teacherNames = DB::table('users')
            ->whereIn('id', $entries->pluck('teacher_id')->filter()->unique())
            ->pluck('name', 'id');

        $venueNames = app(\App\Services\Timetable\VenueService::class)->namesFor($entries, $schoolId);

        $entries->each(function (TimetableEntry $entry) use ($classNames, $teacherNames, $venueNames) {
            $entry->setAttribute('venue_name', $venueNames[$entry->id] ?? null);
            $entry->setAttribute('class_name', $classNames[$entry->school_class_id] ?? null);
            $entry->setAttribute('teacher_name', $entry->teacher_id ? ($teacherNames[$entry->teacher_id] ?? null) : null);
        });

        return response()->json([
            'entries' => $entries,
        ]);
    }

    /**
     * Who teaches what to which class, in the shape the solver takes: subject
     * teachers, and in a section taught by class teachers the class teacher
     * for every subject a specialist does not take (TeachingAssignments).
     *
     * One teacher per class and subject. The solver balances periods per
     * entry, so two teachers for one subject (one per arm, say) would double
     * its periods; a whole-class assignment wins, then any assignment, then
     * the class teacher. The timetable is per class, so a subject taught by
     * different teachers in different arms shows the first of them.
     *
     * A class teacher's periods are exempt from the daily cap: in primary
     * the class teacher is meant to teach the whole day.
     */
    private function assignmentsFromRecords(int $schoolId, array $classIds): array
    {
        $rows = app(TeachingAssignments::class)->rows($schoolId)
            ->filter(fn ($r) => $r->class_id !== null && in_array($r->class_id, $classIds, true))
            ->sortBy(fn ($r) => [$r->source === 'assigned' ? 0 : 1, $r->arm_id === null ? 0 : 1, $r->arm_id ?? 0, $r->teacher_id])
            ->unique(fn ($r) => $r->class_id.':'.$r->subject_id);

        $names = DB::table('subjects')->where('school_id', $schoolId)->whereIn('id', $rows->pluck('subject_id')->all() ?: [0])->pluck('name', 'id');

        return $rows
            ->map(fn ($r) => [
                'class_id' => $r->class_id,
                'teacher_id' => $r->teacher_id,
                'subject_id' => $r->subject_id,
                'subject_name' => $names[$r->subject_id] ?? 'Subject',
                'exempt_daily_cap' => $r->source === 'class_teacher',
            ])
            ->sortBy('subject_name')
            ->values()
            ->all();
    }

    /** "Class 12" in a solver message becomes "JSS 2". */
    private function nameClasses(int $schoolId, string $message): string
    {
        return preg_replace_callback('/Class (\d+)/', function ($m) use ($schoolId) {
            $name = SchoolClass::where('school_id', $schoolId)->where('id', (int) $m[1])->value('name');

            return $name ?? $m[0];
        }, $message);
    }
}
