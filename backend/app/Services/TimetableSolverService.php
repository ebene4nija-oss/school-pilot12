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

        // Each class's options, by index into the assignment list.
        $byClass = [];
        foreach (array_values($teacherSubjectAssignments) as $i => $assignment) {
            $byClass[(int) $assignment['class_id']][$i] = $assignment;
        }

        foreach ($days as $day) {
            foreach ($timeSlots as $slot) {
                $slotId = $slot['id'];
                $slotName = $slot['name'];
                $times = [
                    'start_time' => $slot['start_time'] ?? null,
                    'end_time' => $slot['end_time'] ?? null,
                ];

                foreach ($classIds as $classId) {
                    $classId = (int) $classId;

                    if (! empty($slot['is_break'])) {
                        $timetable[] = [
                            'class_id' => $classId,
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

                    foreach ($byClass[$classId] ?? [] as $i => $assignment) {
                        $teacherId = $assignment['teacher_id'];

                        // Constraint 1: the teacher is not in another class.
                        if (isset($teacherOccupied[$teacherId][$day][$slotId])) {
                            continue;
                        }

                        // Constraint 2: the teacher's daily cap.
                        if (($teacherDailyWorkload[$teacherId][$day] ?? 0) >= $maxDailyTeacherWorkload) {
                            continue;
                        }

                        $key = [$daily[$classId][$day][$i] ?? 0, $weekly[$classId][$i] ?? 0];
                        if ($bestKey === null || $key < $bestKey) {
                            $best = $i;
                            $bestKey = $key;
                        }
                    }

                    if ($best === null) {
                        $conflicts[] = "Conflict: Could not schedule an available teacher for Class {$classId} on {$day} during {$slotName}.";

                        continue;
                    }

                    $assignment = $byClass[$classId][$best];
                    $teacherId = $assignment['teacher_id'];

                    $timetable[] = [
                        'class_id' => $classId,
                        'subject_id' => $assignment['subject_id'] ?? null,
                        'day' => $day,
                        'slot' => $slotName,
                        'subject' => $assignment['subject_name'],
                        'teacher_id' => $teacherId,
                        'is_break' => false,
                    ] + $times;

                    $teacherOccupied[$teacherId][$day][$slotId] = true;
                    $teacherDailyWorkload[$teacherId][$day] = ($teacherDailyWorkload[$teacherId][$day] ?? 0) + 1;
                    $daily[$classId][$day][$best] = ($daily[$classId][$day][$best] ?? 0) + 1;
                    $weekly[$classId][$best] = ($weekly[$classId][$best] ?? 0) + 1;
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
}
