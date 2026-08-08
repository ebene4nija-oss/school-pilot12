<?php

namespace App\Services;

use App\Models\CaScheme;
use App\Models\ScoreEntry;
use App\Models\Student;
use Illuminate\Support\Facades\DB;

/**
 * Turning marks into a grade and a class position.
 *
 * Two things live here rather than in the controller. Grade bands, because the
 * WAEC nine-point scale is a national standard and a copy of the ladder in
 * every controller is a copy that can drift. And class ranking, because doc
 * §7.7 requires it and `score_entries.position_in_class` has existed, unwritten
 * and always null, since the first migration.
 *
 * Note: `CbtExamService::waecGrade()` holds an identical ladder for exam
 * attempts. Left in place deliberately — that file is being edited elsewhere
 * and folding the two together is a separate, mechanical change.
 */
class GradingService
{
    /** WAEC nine-point scale — not letter grades, not a GPA (doc "Hard Rules"). */
    public function waecGrade(float $percentage): string
    {
        return match (true) {
            $percentage >= 75 => 'A1',
            $percentage >= 70 => 'B2',
            $percentage >= 65 => 'B3',
            $percentage >= 60 => 'C4',
            $percentage >= 55 => 'C5',
            $percentage >= 50 => 'C6',
            $percentage >= 45 => 'D7',
            $percentage >= 40 => 'E8',
            default => 'F9',
        };
    }

    /**
     * Total a set of component marks against the school's scheme.
     *
     * Schemes whose weights do not sum to 100 are normalised rather than
     * rejected: a school running 10 + 10 + 40 means "out of 60", and a parent
     * reading the card expects a percentage.
     *
     * @return array{raw: float, percentage: float, grade: string}
     */
    public function total(CaScheme $scheme, float $firstCa, float $secondCa, float $exam): array
    {
        $raw = $firstCa + $secondCa + $exam;
        $weight = $scheme->totalWeight();

        $percentage = $weight > 0 ? round(($raw / $weight) * 100, 2) : 0.0;

        return [
            'raw' => round($raw, 2),
            'percentage' => $percentage,
            'grade' => $this->waecGrade($percentage),
        ];
    }

    /**
     * Recompute and persist every student's position for a class and term.
     *
     * Ranked on the mean across subjects, which is how a Nigerian broadsheet
     * reports it. Ties share a position and the next position skips — two
     * students on 1st are both 1st and the next is 3rd, not 2nd, because
     * telling a parent their child is 2nd when two children scored higher
     * starts an argument the school cannot win.
     *
     * @return int number of students ranked
     */
    public function recomputeClassPositions(int $schoolId, int $classId, int $termId): int
    {
        $studentIds = Student::where('school_id', $schoolId)
            ->where('class_id', $classId)
            ->pluck('id');

        if ($studentIds->isEmpty()) {
            return 0;
        }

        $averages = ScoreEntry::where('school_id', $schoolId)
            ->where('term_id', $termId)
            ->whereIn('student_id', $studentIds)
            ->groupBy('student_id')
            ->select('student_id')
            ->selectRaw('AVG(total_score) as average')
            ->get()
            ->sortByDesc('average')
            ->values();

        $position = 0;
        $seen = 0;
        $previous = null;

        DB::transaction(function () use ($averages, $schoolId, $termId, &$position, &$seen, &$previous) {
            foreach ($averages as $row) {
                $seen++;

                // Equal averages share a position; the run of ties is then
                // skipped over.
                if ($previous === null || round((float) $row->average, 4) !== $previous) {
                    $position = $seen;
                    $previous = round((float) $row->average, 4);
                }

                ScoreEntry::where('school_id', $schoolId)
                    ->where('term_id', $termId)
                    ->where('student_id', $row->student_id)
                    ->update(['position_in_class' => $position]);
            }
        });

        return $averages->count();
    }
}
