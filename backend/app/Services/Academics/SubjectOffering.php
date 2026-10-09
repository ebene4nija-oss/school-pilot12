<?php

namespace App\Services\Academics;

use App\Models\SchoolClass;
use App\Models\Subject;
use App\Support\Sections;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Which subjects a class, an arm or a student takes.
 *
 * A class lists its subjects in `class_subjects`; a row may name the streams
 * that take it (Physics: science; Economics: commercial and arts). An arm has
 * a stream, so SS 2A (science) and SS 2B (arts) get different lists from the
 * same class. An arm with no stream is general or mixed: stream subjects are
 * open to its students but nobody takes them by default — each student picks.
 *
 * A class with no subjects set up yet falls back to the subjects tagged for
 * its level, so a new school's Nursery 1 still never sees Physics.
 */
class SubjectOffering
{
    public const STREAMS = [
        'science' => 'Science',
        'commercial' => 'Commercial',
        'arts' => 'Arts / Humanities',
        'technical' => 'Technical',
    ];

    /**
     * How an arm of this stream takes a class subject: compulsory, elective
     * (open to choose) or null (not offered to it).
     */
    public static function kindFor(object $row, ?string $stream): ?string
    {
        $streams = self::streamsOf($row);
        $kind = $row->is_compulsory ? 'compulsory' : 'elective';

        if ($streams === []) {
            return $kind;
        }

        if ($stream === null) {
            return 'elective';
        }

        return in_array($stream, $streams, true) ? $kind : null;
    }

    /** @return array<int, string> */
    public static function streamsOf(object $row): array
    {
        $streams = $row->streams ?? null;

        if (is_string($streams)) {
            $streams = json_decode($streams, true);
        }

        return array_values(array_filter((array) $streams));
    }

    /** The class's subject rows, keyed by subject id. */
    public function classRows(int $schoolId, int $classId): Collection
    {
        return DB::table('class_subjects')
            ->where('school_id', $schoolId)
            ->where('class_id', $classId)
            ->get(['subject_id', 'is_compulsory', 'streams', 'max_students'])
            ->keyBy('subject_id');
    }

    /** Stream per arm id, for the arms of a school. */
    public function armStreams(int $schoolId): Collection
    {
        return DB::table('arms')->where('school_id', $schoolId)->pluck('stream', 'id');
    }

    /**
     * Subject id => compulsory|elective for one arm of a class (or the whole
     * class when no arm is given). Empty when the class has no subjects set.
     *
     * @return array<int, string>
     */
    public function forArm(int $schoolId, int $classId, ?int $armId): array
    {
        $stream = $armId ? DB::table('arms')->where('school_id', $schoolId)->where('id', $armId)->value('stream') : null;
        $out = [];

        foreach ($this->classRows($schoolId, $classId) as $subjectId => $row) {
            // No arm: the class as a whole, every subject it lists.
            $kind = $armId ? self::kindFor($row, $stream) : ($row->is_compulsory ? 'compulsory' : 'elective');

            if ($kind !== null) {
                $out[(int) $subjectId] = $kind;
            }
        }

        return $out;
    }

    /** Ids of the subjects an arm must take. */
    public function compulsoryFor(int $schoolId, int $classId, ?int $armId): array
    {
        return array_keys(array_filter($this->forArm($schoolId, $classId, $armId), fn ($k) => $k === 'compulsory'));
    }

    /**
     * The subjects to offer in a picker for a class (and arm): the class's own
     * list when it has one, else the active subjects tagged for its level.
     */
    public function subjectsFor(int $schoolId, int $classId, ?int $armId = null): Collection
    {
        $offered = $this->forArm($schoolId, $classId, $armId);

        if ($offered !== []) {
            return Subject::where('school_id', $schoolId)
                ->where('is_active', true)
                ->whereIn('id', array_keys($offered))
                ->orderBy('name')
                ->get()
                ->each(fn (Subject $s) => $s->setAttribute('offered_as', $offered[$s->id]));
        }

        $class = SchoolClass::where('school_id', $schoolId)->find($classId);

        return $class ? $this->subjectsAtLevel($schoolId, Sections::levelGroupOf($class->level_category)) : collect();
    }

    /** Active subjects tagged for a level group (or untagged). */
    public function subjectsAtLevel(int $schoolId, string $levelGroup): Collection
    {
        return Subject::where('school_id', $schoolId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->filter(fn (Subject $s) => Sections::taughtAt($s->level_groups, $levelGroup))
            ->values();
    }
}
