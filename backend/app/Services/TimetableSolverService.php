<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;

class TimetableSolverService
{
    /**
     * Constraint Satisfaction Problem (CSP) Timetable Engine.
     * Guarantees zero double-booking for teachers and classes across periods.
     */
    public function generateTimetable(int $schoolId, array $classIds, array $teacherSubjectAssignments, array $timeSlots): array
    {
        $timetable = [];
        $teacherOccupied = []; // Format: [teacher_id][day][slot_id] => true
        $classOccupied = [];   // Format: [class_id][day][slot_id] => true
        $conflicts = [];

        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

        foreach ($classIds as $classId) {
            foreach ($days as $day) {
                foreach ($timeSlots as $slot) {
                    // Skip break periods
                    if (isset($slot['is_break']) && $slot['is_break']) {
                        $timetable[] = [
                            'class_id' => $classId,
                            'day' => $day,
                            'slot' => $slot['name'],
                            'subject' => 'BREAK',
                            'teacher_id' => null,
                        ];
                        continue;
                    }

                    // Find an available teacher-subject pair for this class
                    $assigned = false;
                    foreach ($teacherSubjectAssignments as $assignment) {
                        if ($assignment['class_id'] !== $classId) {
                            continue;
                        }

                        $teacherId = $assignment['teacher_id'];
                        $subject = $assignment['subject_name'];
                        $slotId = $slot['id'];

                        // CSP Check 1: Teacher double-booking constraint
                        if (isset($teacherOccupied[$teacherId][$day][$slotId])) {
                            continue;
                        }

                        // CSP Check 2: Class double-booking constraint
                        if (isset($classOccupied[$classId][$day][$slotId])) {
                            continue;
                        }

                        // Assign slot
                        $timetable[] = [
                            'class_id' => $classId,
                            'day' => $day,
                            'slot' => $slot['name'],
                            'subject' => $subject,
                            'teacher_id' => $teacherId,
                        ];

                        $teacherOccupied[$teacherId][$day][$slotId] = true;
                        $classOccupied[$classId][$day][$slotId] = true;
                        $assigned = true;
                        break;
                    }

                    if (!$assigned) {
                        $conflicts[] = "Conflict: Could not schedule an available teacher for Class {$classId} on {$day} during {$slot['name']}.";
                    }
                }
            }
        }

        return [
            'success' => count($conflicts) === 0,
            'timetable' => $timetable,
            'conflicts' => $conflicts,
        ];
    }
}
