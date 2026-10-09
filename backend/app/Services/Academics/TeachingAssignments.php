<?php

namespace App\Services\Academics;

use App\Models\SchoolSectionSetting;
use App\Support\Sections;
use App\Support\TeacherScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who teaches which subject to which class and arm — the one answer every
 * right (score entry, broadsheet hand-in, who a teacher may see) asks for.
 *
 * Two sources:
 *  - assigned: `teacher_subjects`, a teacher named for a subject in a class
 *    (every class when none), optionally one arm of it.
 *  - class_teacher: in a section taught by class teachers (nursery and
 *    primary by default), an arm's form and assistant teacher teach every
 *    subject that arm takes this session — except a subject a specialist has
 *    been assigned to for that arm, which is the specialist's alone.
 *
 * A check for a whole class (no arm) is satisfied by teaching any arm of it.
 */
class TeachingAssignments
{
    public const MODELS = [
        'class_teacher' => 'The class teacher teaches every subject',
        'subject_teacher' => 'Each subject has its own teacher',
    ];

    public const DEFAULT_MODELS = [
        'nursery' => 'class_teacher',
        'primary' => 'class_teacher',
        'secondary' => 'subject_teacher',
    ];

    public function __construct(private SubjectOffering $offering)
    {
    }

    /** @return array<string, string> section => model */
    public static function models(int $schoolId): array
    {
        $stored = (array) (SchoolSectionSetting::for($schoolId)->teaching_models ?? []);

        return collect(self::DEFAULT_MODELS)
            ->mapWithKeys(fn ($default, $section) => [$section => isset(self::MODELS[$stored[$section] ?? '']) ? $stored[$section] : $default])
            ->all();
    }

    /**
     * Every teacher-subject-class-arm in force.
     *
     * @return Collection<int, object{teacher_id:int, subject_id:int, class_id:int|null, arm_id:int|null, source:string}>
     */
    public function rows(int $schoolId, ?int $teacherId = null): Collection
    {
        $assigned = DB::table('teacher_subjects')
            ->where('school_id', $schoolId)
            ->when($teacherId, fn ($q) => $q->where('teacher_id', $teacherId))
            ->get(['teacher_id', 'subject_id', 'class_id', 'arm_id'])
            ->map(fn ($r) => (object) [
                'teacher_id' => (int) $r->teacher_id, 'subject_id' => (int) $r->subject_id,
                'class_id' => $r->class_id ? (int) $r->class_id : null, 'arm_id' => $r->arm_id ? (int) $r->arm_id : null,
                'source' => 'assigned',
            ]);

        return $assigned->concat($this->classTeacherRows($schoolId, $teacherId))->values();
    }

    /** Rows for subjects a class teacher teaches by default in their section. */
    private function classTeacherRows(int $schoolId, ?int $teacherId): Collection
    {
        $models = self::models($schoolId);

        $classIds = DB::table('classes')->where('school_id', $schoolId)->get(['id', 'level_category'])
            ->filter(fn ($c) => $models[Sections::sectionOf(Sections::levelGroupOf($c->level_category))] === 'class_teacher')
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        $sessionId = TeacherScope::currentSessionId($schoolId);

        if (! $classIds || ! $sessionId) {
            return collect();
        }

        $placements = DB::table('class_teacher_assignments')
            ->where('school_id', $schoolId)
            ->where('session_id', $sessionId)
            ->whereIn('class_id', $classIds)
            ->when($teacherId, fn ($q) => $q->where(fn ($w) => $w->where('form_teacher_id', $teacherId)->orWhere('assistant_teacher_id', $teacherId)))
            ->get(['class_id', 'arm_id', 'form_teacher_id', 'assistant_teacher_id']);

        if ($placements->isEmpty()) {
            return collect();
        }

        // Specialists take their subject over from the class teacher.
        $specialists = DB::table('teacher_subjects')->where('school_id', $schoolId)
            ->where(fn ($q) => $q->whereIn('class_id', $classIds)->orWhereNull('class_id'))
            ->get(['subject_id', 'class_id', 'arm_id']);

        $armsByClass = DB::table('arms')->where('school_id', $schoolId)->whereIn('class_id', $classIds)->get(['id', 'class_id'])->groupBy('class_id');

        $rows = collect();

        foreach ($placements as $p) {
            $classId = (int) $p->class_id;
            // A whole-class placement covers each arm (or the class, unstreamed).
            $arms = $p->arm_id ? [(int) $p->arm_id] : ($armsByClass->get($classId)?->pluck('id')->map(fn ($id) => (int) $id)->all() ?: [null]);
            $teachers = array_filter([(int) $p->form_teacher_id, $p->assistant_teacher_id ? (int) $p->assistant_teacher_id : null]);

            if ($teacherId) {
                $teachers = array_intersect($teachers, [$teacherId]);
            }

            foreach ($arms as $armId) {
                foreach ($this->offering->subjectsFor($schoolId, $classId, $armId)->pluck('id') as $subjectId) {
                    $taken = $specialists->contains(fn ($s) => (int) $s->subject_id === (int) $subjectId
                        && ($s->class_id === null || (int) $s->class_id === $classId)
                        && ($s->arm_id === null || $armId === null || (int) $s->arm_id === $armId));

                    if ($taken) {
                        continue;
                    }

                    foreach ($teachers as $t) {
                        $rows->push((object) ['teacher_id' => $t, 'subject_id' => (int) $subjectId, 'class_id' => $classId, 'arm_id' => $armId, 'source' => 'class_teacher']);
                    }
                }
            }
        }

        return $rows;
    }

    /** Whether a row covers this class, arm and subject. */
    public static function covers(object $row, int $classId, ?int $armId, int $subjectId): bool
    {
        return $row->subject_id === $subjectId
            && ($row->class_id === null || $row->class_id === $classId)
            && ($row->arm_id === null || $armId === null || $row->arm_id === $armId);
    }

    public function teaches(int $schoolId, int $teacherId, int $classId, ?int $armId, int $subjectId): bool
    {
        return $this->rows($schoolId, $teacherId)->contains(fn ($r) => self::covers($r, $classId, $armId, $subjectId));
    }

    /** @return array<int, int> */
    public function teachersFor(int $schoolId, int $classId, ?int $armId, int $subjectId): array
    {
        return $this->rows($schoolId)
            ->filter(fn ($r) => self::covers($r, $classId, $armId, $subjectId))
            ->pluck('teacher_id')->unique()->values()->all();
    }

    /**
     * Of these students, the ones this teacher does not teach the subject to
     * (by each student's class and arm). Empty when they teach them all.
     *
     * @param  array<int, int>  $studentIds
     * @return array<int, int>
     */
    public function notTaught(int $schoolId, int $teacherId, int $subjectId, array $studentIds): array
    {
        $rows = $this->rows($schoolId, $teacherId)->where('subject_id', $subjectId);

        return DB::table('students')->where('school_id', $schoolId)->whereIn('id', $studentIds ?: [0])
            ->get(['id', 'class_id', 'arm_id'])
            ->reject(fn ($s) => $s->class_id && $rows->contains(fn ($r) => self::covers($r, (int) $s->class_id, $s->arm_id ? (int) $s->arm_id : null, $subjectId)))
            ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }
}
