<?php

namespace App\Services;

use App\Models\CaScheme;
use App\Models\GradingScale;
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
    /**
     * The grade for a percentage on the school's own scale for a class level
     * (GradingScale::bandsFor), falling back to WAEC where none is set.
     */
    public function gradeFor(?int $schoolId, ?string $level, float $percentage): string
    {
        return GradingScale::bandOf(GradingScale::bandsFor($schoolId, $level), $percentage)['grade'];
    }

    /**
     * Re-grade one term's scores for the classes a scale applies to, after the
     * scale changed. One term only: grades already issued on past report cards
     * stay what they were.
     *
     * @return int score entries re-graded
     */
    public function regradeTerm(int $schoolId, int $termId, string $level): int
    {
        $classIds = DB::table('classes')
            ->where('school_id', $schoolId)
            ->when($level !== 'default', fn ($q) => $q->where('level_category', $level))
            ->pluck('level_category', 'id');

        $changed = 0;

        ScoreEntry::withoutGlobalScopes()
            ->where('score_entries.school_id', $schoolId)
            ->where('term_id', $termId)
            ->join('students', 'students.id', '=', 'score_entries.student_id')
            ->whereIn('students.class_id', $classIds->keys())
            ->select('score_entries.*', 'students.class_id as student_class_id')
            ->orderBy('score_entries.id')
            ->chunk(500, function ($entries) use ($schoolId, $classIds, &$changed) {
                foreach ($entries as $entry) {
                    $grade = $this->gradeFor($schoolId, $classIds[$entry->student_class_id] ?? null, (float) $entry->total_score);

                    if ($grade !== $entry->grade) {
                        ScoreEntry::withoutGlobalScopes()->whereKey($entry->id)->update(['grade' => $grade]);
                        $changed++;
                    }
                }
            });

        return $changed;
    }

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
     * The grade is on the school's scale for the student's class level.
     *
     * @return array{raw: float, percentage: float, grade: string}
     */
    public function total(CaScheme $scheme, float $firstCa, float $secondCa, float $exam, ?string $level = null): array
    {
        $raw = $firstCa + $secondCa + $exam;
        $weight = $scheme->totalWeight();

        $percentage = $weight > 0 ? round(($raw / $weight) * 100, 2) : 0.0;

        return [
            'raw' => round($raw, 2),
            'percentage' => $percentage,
            'grade' => $this->gradeFor($scheme->school_id ? (int) $scheme->school_id : null, $level, $percentage),
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
        /*
         * Delegated to the central calculator so that the position printed on
         * a report card and the one on the broadsheet are the same number,
         * ranked by the school's own rule (basis, ties, who is eligible). The
         * default rule is the one this method always applied: mean across
         * subjects, ties share and the next place skips.
         */
        $calc = app(\App\Services\Results\ResultCalculator::class)
            ->forClass($schoolId, $termId, $classId, ['attendance' => false]);

        $ranked = 0;

        DB::transaction(function () use ($calc, $schoolId, $termId, &$ranked) {
            foreach ($calc['students'] as $student) {
                if ($student['position'] !== null) {
                    $ranked++;
                }

                ScoreEntry::where('school_id', $schoolId)
                    ->where('term_id', $termId)
                    ->where('student_id', $student['id'])
                    ->update(['position_in_class' => $student['position']]);
            }
        });

        return $ranked;
    }
}
