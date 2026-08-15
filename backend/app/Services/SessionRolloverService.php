<?php

namespace App\Services;

use App\Models\AcademicSession;
use App\Models\Arm;
use App\Models\SchoolClass;
use App\Models\ScoreEntry;
use App\Models\Student;
use App\Models\StudentClassHistory;
use App\Models\StudentEnrollment;
use App\Models\Term;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * End-of-session rollover.
 *
 * A school does this once a year, for every child at once, and it is the most
 * destructive routine operation in the product: get it wrong and an entire
 * cohort is in the wrong class on resumption day. So it is deliberately two
 * steps — `preview` computes and proposes but writes nothing, `commit` applies
 * decisions the admin has seen and can overrule.
 */
class SessionRolloverService
{
    /**
     * Propose an outcome for every student in the outgoing session.
     *
     * The proposal is advisory. Nothing here decides anything on its own — a
     * borderline average is a conversation between a head teacher and a
     * parent, not a threshold in a service class.
     */
    public function preview(
        int $schoolId,
        AcademicSession $fromSession,
        ?int $classId = null,
        float $passMark = 40.0,
    ): array {
        $classes = SchoolClass::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->orderBy('order_index')
            ->get();

        $enrollments = StudentEnrollment::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('session_id', $fromSession->id)
            ->where('status', 'active')
            ->when($classId, fn ($q) => $q->where('class_id', $classId))
            ->with(['student.user:id,name', 'schoolClass:id,name', 'arm:id,name'])
            ->get();

        $averages = $this->sessionAverages(
            $schoolId,
            $fromSession,
            $enrollments->pluck('student_id')->all()
        );

        $rows = [];

        foreach ($enrollments as $enrollment) {
            $currentClass = $classes->firstWhere('id', $enrollment->class_id);
            $nextClass = $this->nextClass($classes, $currentClass);
            $average = $averages[$enrollment->student_id] ?? null;

            /*
             * Three-way decision, in order of precedence:
             *   1. terminal class  → graduate, whatever the average
             *   2. no scores       → promote, and say so; a mid-session
             *                        arrival with an empty score sheet must
             *                        not be held back by an average of zero
             *   3. average vs pass mark
             */
            if ($currentClass?->is_exit_class || $nextClass === null) {
                $proposed = 'graduate';
                $targetClassId = null;
                $reason = $currentClass?->is_exit_class
                    ? 'Final class of the school.'
                    : 'No class above ' . ($currentClass->name ?? 'this one') . '.';
            } elseif ($average === null) {
                $proposed = 'promote';
                $targetClassId = $nextClass->id;
                $reason = 'No scores recorded this session — promoted by default, please confirm.';
            } elseif ($average >= $passMark) {
                $proposed = 'promote';
                $targetClassId = $nextClass->id;
                $reason = 'Session average ' . $average . '% meets the ' . $passMark . '% pass mark.';
            } else {
                $proposed = 'repeat';
                $targetClassId = $enrollment->class_id;
                $reason = 'Session average ' . $average . '% is below the ' . $passMark . '% pass mark.';
            }

            $rows[] = [
                'student_id' => $enrollment->student_id,
                'student_name' => $enrollment->student?->user?->name,
                'admission_number' => $enrollment->student?->admission_number,
                'current_class_id' => $enrollment->class_id,
                'current_class' => $enrollment->schoolClass->name ?? null,
                'current_arm_id' => $enrollment->arm_id,
                'current_arm' => $enrollment->arm->name ?? null,
                'session_average' => $average,
                'proposed_action' => $proposed,
                'proposed_class_id' => $targetClassId,
                'proposed_class' => $targetClassId
                    ? ($classes->firstWhere('id', $targetClassId)->name ?? null)
                    : null,
                // The arm does not carry over automatically: streaming is
                // usually re-decided on results, so the admin picks.
                'proposed_arm_id' => null,
                'reason' => $reason,
            ];
        }

        return [
            'from_session' => ['id' => $fromSession->id, 'name' => $fromSession->name],
            'pass_mark' => $passMark,
            'students' => $rows,
            'summary' => [
                'total' => count($rows),
                'promote' => count(array_filter($rows, fn ($r) => $r['proposed_action'] === 'promote')),
                'repeat' => count(array_filter($rows, fn ($r) => $r['proposed_action'] === 'repeat')),
                'graduate' => count(array_filter($rows, fn ($r) => $r['proposed_action'] === 'graduate')),
                'without_scores' => count(array_filter($rows, fn ($r) => $r['session_average'] === null)),
            ],
        ];
    }

    /**
     * Apply a set of decisions. All or nothing.
     *
     * A partially-applied rollover is worse than none at all: half the school
     * moved up and no record of which half. One transaction.
     */
    public function commit(
        int $schoolId,
        AcademicSession $fromSession,
        AcademicSession $toSession,
        array $decisions,
        int $performedBy,
    ): array {
        $studentIds = array_column($decisions, 'student_id');

        return DB::transaction(function () use (
            $schoolId, $fromSession, $toSession, $decisions, $performedBy, $studentIds
        ) {
            $students = Student::withoutGlobalScopes()
                ->where('school_id', $schoolId)
                ->whereIn('id', $studentIds)
                ->get()
                ->keyBy('id');

            $openEnrollments = StudentEnrollment::withoutGlobalScopes()
                ->where('school_id', $schoolId)
                ->where('session_id', $fromSession->id)
                ->whereIn('student_id', $studentIds)
                ->where('status', 'active')
                ->get()
                ->keyBy('student_id');

            $applied = [];
            $skipped = [];

            foreach ($decisions as $decision) {
                $student = $students->get($decision['student_id']);

                // Silently ignoring a student the admin listed would leave
                // them behind with no trace. Named and returned instead.
                if (! $student) {
                    $skipped[] = [
                        'student_id' => $decision['student_id'],
                        'reason' => 'Not a student at this school.',
                    ];
                    continue;
                }

                $enrollment = $openEnrollments->get($student->id);
                $action = $decision['action'];
                $outcome = $this->outcomeFor($action);

                if ($enrollment) {
                    $enrollment->update([
                        'status' => 'closed',
                        'outcome' => $outcome,
                        'closed_on' => $toSession->start_date ?? now()->toDateString(),
                        'destination_school' => $decision['destination_school'] ?? null,
                        'outcome_remarks' => $decision['remarks'] ?? null,
                        'recorded_by' => $performedBy,
                    ]);
                }

                $fromClassId = $enrollment->class_id ?? $student->class_id;
                $fromArmId = $enrollment->arm_id ?? $student->arm_id;

                if (in_array($outcome, StudentEnrollment::EXIT_OUTCOMES, true)) {
                    /*
                     * The student leaves. No enrolment in the new session, and
                     * the placement pointer is cleared so they stop appearing
                     * on next year's registers — the record itself is kept,
                     * because results and invoices still reference it.
                     */
                    $student->update([
                        'status' => match ($outcome) {
                            'graduated' => 'graduated',
                            'transferred_out' => 'transferred',
                            'withdrawn' => 'withdrawn',
                        },
                    ]);
                } else {
                    $targetClassId = $outcome === 'repeated'
                        ? ($decision['target_class_id'] ?? $fromClassId)
                        : $decision['target_class_id'];

                    StudentEnrollment::updateOrCreate(
                        ['student_id' => $student->id, 'session_id' => $toSession->id],
                        [
                            'school_id' => $schoolId,
                            'class_id' => $targetClassId,
                            'arm_id' => $decision['target_arm_id'] ?? null,
                            'status' => 'active',
                            'outcome' => null,
                            'enrolled_on' => $toSession->start_date ?? now()->toDateString(),
                            'recorded_by' => $performedBy,
                        ]
                    );

                    $student->update([
                        'class_id' => $targetClassId,
                        'arm_id' => $decision['target_arm_id'] ?? null,
                        'status' => 'active',
                    ]);
                }

                /*
                 * The legacy audit trail keeps being written. Its `action`
                 * column only knows promote/repeat/transfer, so graduate and
                 * withdraw are recorded as transfers there with the real
                 * outcome spelled out in the remarks; the enrolment row above
                 * is the precise record.
                 */
                StudentClassHistory::create([
                    'school_id' => $schoolId,
                    'student_id' => $student->id,
                    'from_class_id' => $fromClassId,
                    'from_arm_id' => $fromArmId,
                    'to_class_id' => $student->class_id ?? $fromClassId,
                    'to_arm_id' => $student->arm_id,
                    'session_id' => $toSession->id,
                    'action' => match ($outcome) {
                        'promoted' => 'promote',
                        'repeated' => 'repeat',
                        default => 'transfer',
                    },
                    'remarks' => trim(ucfirst($outcome) . '. ' . ($decision['remarks'] ?? '')),
                    'performed_by' => $performedBy,
                ]);

                $applied[] = [
                    'student_id' => $student->id,
                    'outcome' => $outcome,
                    'class_id' => $student->class_id,
                ];
            }

            return ['applied' => $applied, 'skipped' => $skipped];
        });
    }

    /**
     * Mean of a student's subject totals across every term in the session.
     *
     * One grouped query for the whole cohort. Doing this per student turned a
     * 600-pupil rollover preview into 600 round trips.
     */
    private function sessionAverages(int $schoolId, AcademicSession $session, array $studentIds): array
    {
        if (empty($studentIds)) {
            return [];
        }

        $termIds = Term::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('session_id', $session->id)
            ->pluck('id');

        if ($termIds->isEmpty()) {
            return [];
        }

        return ScoreEntry::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->whereIn('term_id', $termIds)
            ->whereIn('student_id', $studentIds)
            ->selectRaw('student_id, AVG(total_score) as average')
            ->groupBy('student_id')
            ->pluck('average', 'student_id')
            ->map(fn ($average) => round((float) $average, 2))
            ->all();
    }

    /** The next class up by teaching order, or null at the top of the school. */
    private function nextClass(Collection $classes, ?SchoolClass $current): ?SchoolClass
    {
        if (! $current) {
            return null;
        }

        return $classes
            ->where('order_index', '>', $current->order_index)
            ->sortBy('order_index')
            ->first();
    }

    private function outcomeFor(string $action): string
    {
        return match ($action) {
            'promote' => 'promoted',
            'repeat' => 'repeated',
            'graduate' => 'graduated',
            'transfer_out' => 'transferred_out',
            'withdraw' => 'withdrawn',
        };
    }

    /**
     * Arms belonging to a class, for the picker on the preview screen.
     */
    public function armsFor(int $schoolId, array $classIds): array
    {
        return Arm::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->whereIn('class_id', $classIds)
            ->orderBy('name')
            ->get(['id', 'class_id', 'name'])
            ->groupBy('class_id')
            ->map(fn ($arms) => $arms->map(fn ($a) => ['id' => $a->id, 'name' => $a->name])->values())
            ->all();
    }
}
