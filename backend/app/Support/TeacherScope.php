<?php

namespace App\Support;

use App\Models\AcademicSession;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Which students a teacher may see: the ones they teach.
 *
 * A teacher teaches a student when, for the student's current class, they are
 * - its form or assistant teacher this session (for the student's arm, or for
 *   the whole class when the assignment names no arm), or
 * - assigned to teach any subject in it (`teacher_subjects`): in the
 *   student's arm when the assignment names one, else in every arm.
 *
 * A teacher whose school admin has granted `sees_all_students` sees everyone,
 * as does any role that is not a teacher (the policy decides those).
 */
class TeacherScope
{
    /** Per-request memo: the same teacher is checked many times per page. */
    private static array $memo = [];

    public static function seesWholeSchool(User $user): bool
    {
        return (bool) $user->userProfile?->sees_all_students;
    }

    /**
     * @return array{classes: array<int,int>, arms: array<int,array{0:int,1:int}>}
     *   `classes`: class ids taught whole; `arms`: [class_id, arm_id] pairs.
     */
    public static function placements(User $user): array
    {
        $schoolId = (int) $user->userProfile?->school_id;
        $key = $user->id.':'.$schoolId;

        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }

        $classes = [];
        $arms = [];

        // A subject assignment to one arm covers that arm; with no arm, the class.
        $taught = DB::table('teacher_subjects')
            ->where('school_id', $schoolId)
            ->where('teacher_id', $user->id)
            ->whereNotNull('class_id')
            ->get(['class_id', 'arm_id']);

        foreach ($taught as $row) {
            $row->arm_id === null
                ? $classes[] = (int) $row->class_id
                : $arms[] = [(int) $row->class_id, (int) $row->arm_id];
        }
        $sessionId = self::currentSessionId($schoolId);

        if ($sessionId) {
            $rows = DB::table('class_teacher_assignments')
                ->where('school_id', $schoolId)
                ->where('session_id', $sessionId)
                ->where(fn ($q) => $q->where('form_teacher_id', $user->id)->orWhere('assistant_teacher_id', $user->id))
                ->get(['class_id', 'arm_id']);

            foreach ($rows as $row) {
                if ($row->arm_id === null) {
                    $classes[] = (int) $row->class_id;
                } else {
                    $arms[] = [(int) $row->class_id, (int) $row->arm_id];
                }
            }
        }

        return self::$memo[$key] = ['classes' => array_values(array_unique($classes)), 'arms' => $arms];
    }

    /** Whether this teacher teaches this student. */
    public static function teaches(User $user, Student $student): bool
    {
        $placements = self::placements($user);

        if (in_array((int) $student->class_id, $placements['classes'], true)) {
            return true;
        }

        foreach ($placements['arms'] as [$classId, $armId]) {
            if ((int) $student->class_id === $classId && (int) $student->arm_id === $armId) {
                return true;
            }
        }

        return false;
    }

    /** Narrow a student query to the ones this teacher teaches. */
    public static function apply(Builder $query, User $user): Builder
    {
        $placements = self::placements($user);
        $table = $query->getModel()->getTable();

        return $query->where(function ($q) use ($placements, $table) {
            // No placements at all: match nothing, rather than everything.
            $q->whereRaw('1 = 0');

            if ($placements['classes'] !== []) {
                $q->orWhereIn("{$table}.class_id", $placements['classes']);
            }

            foreach ($placements['arms'] as [$classId, $armId]) {
                $q->orWhere(fn ($w) => $w->where("{$table}.class_id", $classId)->where("{$table}.arm_id", $armId));
            }
        });
    }

    /**
     * This school's current session: the one today falls in, else the one an
     * admin marked current, else the most recently started. Mirrors how
     * `GET /terms` picks the current term, so the two never disagree.
     */
    public static function currentSessionId(int $schoolId): ?int
    {
        $sessions = AcademicSession::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->orderByDesc('start_date')
            ->get(['id', 'start_date', 'end_date', 'is_current']);

        $today = now()->toDateString();

        $current = $sessions->first(fn ($s) => (string) $s->start_date <= $today && (string) $s->end_date >= $today)
            ?? $sessions->firstWhere('is_current', true)
            ?? $sessions->first();

        return $current?->id;
    }

    /** For tests, which change assignments between requests in one process. */
    public static function forget(): void
    {
        self::$memo = [];
    }
}
