<?php

namespace App\Services\Timetable;

use App\Exceptions\DomainRefusal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cover for absent teachers and clash checks on a timetable (TASK 07).
 *
 * A teacher is absent on a date if an approved leave request covers it or
 * someone recorded them absent for the day. Their published lessons that
 * day need cover; a substitute must be free at that time, by the timetable
 * and by any other cover already arranged.
 */
class CoverService
{
    /** @return array<int, int> user ids absent on the date */
    public function absentTeachers(int $schoolId, Carbon $date): array
    {
        $onLeave = DB::table('leave_requests')->join('staff', 'staff.id', '=', 'leave_requests.staff_id')
            ->where('leave_requests.school_id', $schoolId)->where('leave_requests.status', 'approved')
            ->whereDate('leave_requests.start_date', '<=', $date)->whereDate('leave_requests.end_date', '>=', $date)
            ->pluck('staff.user_id');
        $marked = DB::table('teacher_absences')->where('school_id', $schoolId)->whereDate('date', $date)->pluck('teacher_id');

        return $onLeave->merge($marked)->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** The published lessons on a date's weekday. */
    public function lessonsOn(int $schoolId, Carbon $date): Collection
    {
        return DB::table('timetable_entries as e')->join('timetable_versions as v', 'v.id', '=', 'e.timetable_version_id')
            ->leftJoin('classes as c', 'c.id', '=', 'e.school_class_id')
            ->where('v.school_id', $schoolId)->where('v.status', 'published')->where('e.day_of_week', $date->format('l'))->where('e.is_break', false)
            ->get(['e.id', 'e.school_class_id', 'c.name as class', 'e.subject_id', 'e.subject_name', 'e.teacher_id', 'e.slot_name', 'e.start_time', 'e.end_time']);
    }

    /** Lessons needing cover, with what has been arranged and who could take each. */
    public function coverSheet(int $schoolId, Carbon $date): array
    {
        $absent = $this->absentTeachers($schoolId, $date);
        $lessons = $this->lessonsOn($schoolId, $date);
        $arranged = DB::table('timetable_substitutions')->where('school_id', $schoolId)->whereDate('date', $date)->where('status', 'covered')->get()->keyBy('entry_id');
        $names = DB::table('users')->whereIn('id', array_merge($absent, $arranged->pluck('substitute_teacher_id')->all(), $lessons->pluck('teacher_id')->filter()->all()))->pluck('name', 'id');

        $needs = $lessons->filter(fn ($l) => $l->teacher_id && in_array((int) $l->teacher_id, $absent, true))->sortBy('start_time')->values();

        return [
            'date' => $date->toDateString(),
            'absent' => array_map(fn ($id) => ['id' => $id, 'name' => $names[$id] ?? null], $absent),
            'lessons' => $needs->map(fn ($l) => [
                'entry_id' => $l->id, 'class' => $l->class, 'subject' => $l->subject_name, 'slot' => $l->slot_name,
                'start_time' => $l->start_time, 'end_time' => $l->end_time, 'absent_teacher' => $names[$l->teacher_id] ?? null,
                'cover' => ($s = $arranged->get($l->id)) ? ['id' => $s->id, 'teacher_id' => $s->substitute_teacher_id, 'teacher' => $names[$s->substitute_teacher_id] ?? null] : null,
                'suggestions' => $arranged->has($l->id) ? [] : $this->freeTeachers($schoolId, $date, $l, $absent, $lessons)->take(5)->values(),
            ])->all(),
        ];
    }

    /** Free at the lesson's time; same-subject teachers first, then lightest day. */
    public function freeTeachers(int $schoolId, Carbon $date, object $lesson, array $absent, Collection $lessons): Collection
    {
        $busy = $lessons->filter(fn ($o) => $o->teacher_id && $this->overlaps($o, $lesson))->pluck('teacher_id')
            ->merge(DB::table('timetable_substitutions as s')->join('timetable_entries as e', 'e.id', '=', 's.entry_id')
                ->where('s.school_id', $schoolId)->whereDate('s.date', $date)->where('s.status', 'covered')
                ->get(['s.substitute_teacher_id', 'e.start_time', 'e.end_time', 'e.slot_name'])
                ->filter(fn ($o) => $this->overlaps($o, $lesson))->pluck('substitute_teacher_id'))
            ->map(fn ($id) => (int) $id)->all();
        $load = $lessons->countBy('teacher_id');
        $subjectTeachers = DB::table('teacher_subjects')->where('school_id', $schoolId)->where('subject_id', $lesson->subject_id)->pluck('teacher_id')->map(fn ($id) => (int) $id)->all();

        return DB::table('user_profiles')->join('users', 'users.id', '=', 'user_profiles.user_id')
            ->where('user_profiles.school_id', $schoolId)->where('user_profiles.role', 'teacher')
            ->whereNotIn('users.id', array_merge($absent, $busy) ?: [0])->get(['users.id', 'users.name'])
            ->map(fn ($t) => ['id' => (int) $t->id, 'name' => $t->name, 'teaches_subject' => in_array((int) $t->id, $subjectTeachers, true), 'lessons_today' => (int) ($load[$t->id] ?? 0)])
            ->sortBy(fn ($t) => [$t['teaches_subject'] ? 0 : 1, $t['lessons_today']])->values();
    }

    public function assign(int $schoolId, Carbon $date, int $entryId, int $substituteId, int $byUserId, ?string $note): int
    {
        $lessons = $this->lessonsOn($schoolId, $date);
        $lesson = $lessons->firstWhere('id', $entryId);
        if (! $lesson) {
            throw new DomainRefusal('That lesson is not on the published timetable for this date.');
        }
        $absent = $this->absentTeachers($schoolId, $date);
        if (! $this->freeTeachers($schoolId, $date, $lesson, $absent, $lessons)->contains('id', $substituteId)) {
            throw new DomainRefusal('That teacher is not free at this time, is absent, or is not a teacher at this school.');
        }

        return DB::transaction(function () use ($schoolId, $date, $lesson, $substituteId, $byUserId, $note) {
            DB::table('timetable_substitutions')->where('school_id', $schoolId)->where('entry_id', $lesson->id)->whereDate('date', $date)->where('status', 'covered')
                ->update(['status' => 'cancelled', 'updated_at' => now()]);

            return DB::table('timetable_substitutions')->insertGetId([
                'school_id' => $schoolId, 'entry_id' => $lesson->id, 'date' => $date->toDateString(), 'absent_teacher_id' => $lesson->teacher_id,
                'substitute_teacher_id' => $substituteId, 'status' => 'covered', 'note' => $note, 'arranged_by' => $byUserId, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    /**
     * Teachers in two places at once in a version, before it is published.
     *
     * @return array<int, array<string, mixed>>
     */
    public function clashes(int $versionId): array
    {
        $entries = DB::table('timetable_entries as e')->leftJoin('classes as c', 'c.id', '=', 'e.school_class_id')->leftJoin('users as u', 'u.id', '=', 'e.teacher_id')
            ->where('e.timetable_version_id', $versionId)->whereNotNull('e.teacher_id')->where('e.is_break', false)
            ->get(['e.id', 'e.teacher_id', 'u.name as teacher', 'e.day_of_week', 'e.slot_name', 'e.start_time', 'e.end_time', 'c.name as class']);

        $out = [];
        foreach ($entries->groupBy(fn ($e) => $e->teacher_id.'|'.$e->day_of_week) as $group) {
            $list = $group->values();
            for ($i = 0; $i < $list->count(); $i++) {
                for ($j = $i + 1; $j < $list->count(); $j++) {
                    if ($this->overlaps($list[$i], $list[$j])) {
                        $out[] = ['teacher' => $list[$i]->teacher, 'day' => $list[$i]->day_of_week, 'slot' => $list[$i]->slot_name, 'classes' => [$list[$i]->class, $list[$j]->class]];
                    }
                }
            }
        }

        return $out;
    }

    /** Same slot name, or overlapping clock times where both are known. */
    private function overlaps(object $a, object $b): bool
    {
        if ($a->start_time && $a->end_time && $b->start_time && $b->end_time) {
            return $a->start_time < $b->end_time && $b->start_time < $a->end_time;
        }

        return $a->slot_name === $b->slot_name;
    }
}
