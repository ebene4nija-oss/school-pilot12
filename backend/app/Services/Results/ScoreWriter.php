<?php

namespace App\Services\Results;

use App\Exceptions\DomainRefusal;
use App\Models\CaScheme;
use App\Models\ScoreEntry;
use App\Models\Student;
use App\Services\GradingService;
use App\Services\ScoreInput;
use App\Support\Sections;

/**
 * Saves one student's marks for a subject and term, against the CA scheme in
 * force, refusing results that are submitted, approved or published. Manual
 * score entry and CA filled from coursework both go through here, so they
 * obey the same rules.
 */
class ScoreWriter
{
    public function __construct(private GradingService $grading, private BroadsheetWorkflow $workflow)
    {
    }

    /** The scheme in force for this student's section and term. */
    public function schemeFor(int $schoolId, int $termId, int $studentId): CaScheme
    {
        $placement = Student::withoutGlobalScopes()->where('school_id', $schoolId)->with('currentClass:id,level_category')->find($studentId);

        return CaScheme::forTerm($schoolId, $termId, Sections::levelGroupOf($placement?->currentClass?->level_category));
    }

    /**
     * @param  array<string, mixed>  $input  components / first_ca / second_ca / exam
     * @return array{entry: ScoreEntry, scheme: CaScheme}
     *
     * @throws DomainRefusal when the result is locked
     * @throws \Illuminate\Validation\ValidationException on a mark outside the scheme
     */
    public function write(int $schoolId, int $termId, int $studentId, int $subjectId, array $input, ?string $state = null, bool $recomputePositions = true): array
    {
        $placement = Student::withoutGlobalScopes()->where('school_id', $schoolId)->with('currentClass:id,level_category')->find($studentId);

        // The scheme in force for this term and section (decision D1): a
        // newer scheme never reaches a term already being marked.
        $scheme = CaScheme::forTerm($schoolId, $termId, Sections::levelGroupOf($placement?->currentClass?->level_category));

        // Submitted, approved and published results are not edited in place.
        if ($placement?->class_id) {
            $blocked = $this->workflow->editBlockReason($schoolId, $termId, (int) $placement->class_id, $studentId, $subjectId);
            if ($blocked !== null) {
                throw new DomainRefusal($blocked);
            }
        }

        $existing = ScoreEntry::where('school_id', $schoolId)->where('term_id', $termId)->where('student_id', $studentId)->where('subject_id', $subjectId)->value('component_scores');

        // A mark per part of the school's scheme (any number of CAs), or the
        // classic first_ca / second_ca / exam fields. See ScoreInput.
        $marks = ScoreInput::resolve($scheme, $input, is_string($existing) ? json_decode($existing, true) : $existing);

        // Graded on the school's scale for this student's class level.
        $computed = $this->grading->total($scheme, $marks['first_ca'], $marks['second_ca'], $marks['exam'], $placement?->currentClass?->level_category);

        $entry = ScoreEntry::updateOrCreate(
            ['school_id' => $schoolId, 'term_id' => $termId, 'student_id' => $studentId, 'subject_id' => $subjectId],
            [
                'first_ca' => $marks['first_ca'],
                'second_ca' => $marks['second_ca'],
                'exam' => $marks['exam'],
                'component_scores' => $marks['component_scores'],
                'total_score' => $computed['percentage'],
                'grade' => $computed['grade'],
                'result_state' => in_array($state, ['absent', 'exempt'], true) ? $state : null,
            ]
        );

        // Positions shift for the whole class whenever one mark changes, so
        // they are recomputed here rather than left null (doc §7.7).
        if ($recomputePositions && $placement?->class_id) {
            $this->grading->recomputeClassPositions($schoolId, (int) $placement->class_id, $termId);
            $entry->refresh();
        }

        return ['entry' => $entry, 'scheme' => $scheme];
    }
}
