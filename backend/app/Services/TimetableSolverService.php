<?php

namespace App\Services;

use App\Models\TimetableEntry;
use App\Models\TimetableVersion;
use Illuminate\Support\Facades\DB;

class TimetableSolverService
{
    /**
     * Constraint Satisfaction Problem (CSP) Timetable Engine.
     * Guarantees zero double-booking for teachers and classes across periods,
     * respects teacher daily workload caps, and persists draft versions.
     *
     * Periods are filled slot by slot across all classes (Monday Period 1 for
     * every class, then Period 2, …) rather than class by class, so the first
     * class in the list cannot take every teacher's day before the others are
     * considered.
     *
     * For each period a class gets the subject it has had least today, then
     * least this week. Picking simply the first free teacher gave a class
     * Mathematics for six periods straight.
     */
    public function generateTimetable(
        int $schoolId,
        array $classIds,
        array $teacherSubjectAssignments,
        array $timeSlots,
        array $options = []
    ): array {
        $timetable = [];
        $teacherOccupied = []; // [teacher_id][day][slot_id] => true
        $teacherDailyWorkload = []; // [teacher_id][day] => int
        $weekly = []; // [class_id][assignment index] => periods this week
        $daily = []; // [class_id][day][assignment index] => periods today
        $conflicts = [];

        $days = $options['days'] ?? ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
        $maxDailyTeacherWorkload = $options['max_daily_teacher_workload'] ?? 6;

        /*
         * What gets a timetable: each arm of a class, or the class itself when
         * it is taught as one group. An assignment naming an arm belongs to
         * that arm's timetable; one with no arm, to the class's. Keys are
         * "class:arm" ("12:" for an unstreamed class). A teacher can be in only
         * one arm at a time, which is what keeps SS 2A and SS 2B apart.
         */
        $byClass = [];
        $groups = [];
        foreach (array_values($teacherSubjectAssignments) as $i => $assignment) {
            $armId = isset($assignment['arm_id']) ? (int) $assignment['arm_id'] : null;
            $key = (int) $assignment['class_id'].':'.($armId ?? '');
            $byClass[$key][$i] = $assignment;
            $groups[(int) $assignment['class_id']][$key] = $armId;
        }

        foreach ($days as $day) {
            foreach ($timeSlots as $slot) {
                $slotId = $slot['id'];
                $slotName = $slot['name'];
                $times = [
                    'start_time' => $slot['start_time'] ?? null,
                    'end_time' => $slot['end_time'] ?? null,
                ];

                foreach ($this->groupsInOrder($classIds, $groups) as [$classId, $armId, $groupKey]) {
                    if (! empty($slot['is_break'])) {
                        $timetable[] = [
                            'class_id' => $classId,
                            'arm_id' => $armId,
                            'day' => $day,
                            'slot' => $slotName,
                            'subject' => 'BREAK',
                            'teacher_id' => null,
                            'is_break' => true,
                        ] + $times;

                        continue;
                    }

                    $best = null;
                    $bestKey = null;

                    foreach ($byClass[$groupKey] ?? [] as $i => $assignment) {
                        $teacherId = $assignment['teacher_id'];

                        // Constraint 1: the teacher is not in another class.
                        if (isset($teacherOccupied[$teacherId][$day][$slotId])) {
                            continue;
                        }

                        // Constraint 2: the teacher's daily cap — not for a
                        // class teacher, who teaches their class all day.
                        if (empty($assignment['exempt_daily_cap']) && ($teacherDailyWorkload[$teacherId][$day] ?? 0) >= $maxDailyTeacherWorkload) {
                            continue;
                        }

                        // Spread subjects across the day and week first; among
                        // equals, the teacher with most of the day left.
                        $key = [$daily[$groupKey][$day][$i] ?? 0, $weekly[$groupKey][$i] ?? 0, $teacherDailyWorkload[$teacherId][$day] ?? 0];
                        if ($bestKey === null || $key < $bestKey) {
                            $best = $i;
                            $bestKey = $key;
                        }
                    }

                    if ($best === null) {
                        $conflicts[] = "Conflict: Could not schedule an available teacher for Class {$classId}".($armId ? " Arm {$armId}" : '')." on {$day} during {$slotName}.";

                        continue;
                    }

                    $assignment = $byClass[$groupKey][$best];
                    $teacherId = $assignment['teacher_id'];

                    $timetable[] = [
                        'class_id' => $classId,
                        'arm_id' => $armId,
                        'subject_id' => $assignment['subject_id'] ?? null,
                        'day' => $day,
                        'slot' => $slotName,
                        'subject' => $assignment['subject_name'],
                        'teacher_id' => $teacherId,
                        'is_break' => false,
                    ] + $times;

                    $teacherOccupied[$teacherId][$day][$slotId] = true;
                    $teacherDailyWorkload[$teacherId][$day] = ($teacherDailyWorkload[$teacherId][$day] ?? 0) + 1;
                    $daily[$groupKey][$day][$best] = ($daily[$groupKey][$day][$best] ?? 0) + 1;
                    $weekly[$groupKey][$best] = ($weekly[$groupKey][$best] ?? 0) + 1;
                }
            }
        }

        $success = count($conflicts) === 0;
        $version = null;

        // Automatically persist draft if requested or on successful resolution
        if (($options['save_draft'] ?? true) && $success) {
            $versionName = $options['version_name'] ?? ('Generated Timetable ' . date('Y-m-d H:i'));
            $version = DB::transaction(function () use ($schoolId, $versionName, $timetable, $options) {
                $tv = TimetableVersion::create([
                    'school_id' => $schoolId,
                    'name' => $versionName,
                    'status' => 'draft',
                    'academic_session_id' => $options['academic_session_id'] ?? null,
                    'term_id' => $options['term_id'] ?? null,
                ]);

                $now = now();
                $rows = array_map(fn ($entry) => [
                    'timetable_version_id' => $tv->id,
                    'school_class_id' => $entry['class_id'],
                    'arm_id' => $entry['arm_id'] ?? null,
                    'subject_id' => $entry['subject_id'] ?? null,
                    'subject_name' => $entry['subject'],
                    'teacher_id' => $entry['teacher_id'],
                    'day_of_week' => $entry['day'],
                    'slot_name' => $entry['slot'],
                    'start_time' => $entry['start_time'] ?? null,
                    'end_time' => $entry['end_time'] ?? null,
                    'is_break' => $entry['is_break'] ?? false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $timetable);

                // A whole school is a few hundred rows; one insert per row
                // made generation take seconds.
                foreach (array_chunk($rows, 200) as $chunk) {
                    TimetableEntry::insert($chunk);
                }

                return $tv;
            });
        }

        return [
            'success' => $success,
            'timetable' => $timetable,
            'conflicts' => $conflicts,
            'version' => $version,
        ];
    }

    /**
     * [class id, arm id or null, group key] for every timetable to build, in
     * the order the classes were given and arm by arm within each. A class
     * with no assignments still yields its own key, so it is reported as a
     * conflict rather than silently skipped.
     *
     * @return array<int, array{0:int, 1:int|null, 2:string}>
     */
    private function groupsInOrder(array $classIds, array $groups): array
    {
        $out = [];

        foreach ($classIds as $classId) {
            $classId = (int) $classId;
            $armGroups = $groups[$classId] ?? [$classId.':' => null];
            ksort($armGroups, SORT_NATURAL);

            foreach ($armGroups as $key => $armId) {
                $out[] = [$classId, $armId, (string) $key];
            }
        }

        return $out;
    }
}
