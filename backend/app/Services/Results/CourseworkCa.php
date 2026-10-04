<?php

namespace App\Services\Results;

use App\Exceptions\DomainRefusal;
use App\Models\Homework;
use App\Models\ScoreEntry;
use App\Models\Student;
use App\Models\Term;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A CA filled from coursework (TASK 08, 21). The teacher picks which homework
 * and quizzes count towards a CA; each student's percentage across them is
 * scaled to the CA's mark, and the teacher adjusts any mark before it is
 * entered. Nothing reaches the score sheet without the teacher applying it.
 */
class CourseworkCa
{
    public function __construct(private ScoreWriter $writer)
    {
    }

    /** Graded homework and quizzes for this class, subject and term. */
    public function sources(int $schoolId, int $termId, int $classId, int $subjectId): array
    {
        $term = Term::where('school_id', $schoolId)->findOrFail($termId);

        $homework = Homework::where('school_id', $schoolId)->where('class_id', $classId)->where('subject_id', $subjectId)
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'draft'))
            ->whereBetween('due_date', [$term->start_date, $term->end_date])
            ->orderBy('due_date')->get(['id', 'title', 'due_date', 'max_score'])
            ->map(fn ($h) => [
                'id' => $h->id, 'title' => $h->title, 'date' => $h->due_date?->toDateString(), 'max_score' => $h->max_score,
                'graded' => DB::table('homework_submissions')->where('homework_id', $h->id)->whereNotNull('marks_awarded')->count(),
            ]);

        $exams = DB::table('cbt_exams')->where('school_id', $schoolId)->where('class_id', $classId)->where('subject_id', $subjectId)
            ->where(fn ($q) => $q->where('term_id', $termId)->orWhereNull('term_id'))
            ->orderBy('id')->get(['id', 'title', 'exam_type'])
            ->map(fn ($e) => [
                'id' => (int) $e->id, 'title' => $e->title, 'type' => $e->exam_type,
                'graded' => DB::table('cbt_attempts')->where('exam_id', $e->id)->where('status', 'graded')->distinct()->count('student_id'),
            ]);

        return ['homework' => $homework->values(), 'quizzes' => $exams->values()];
    }

    /**
     * Each student's suggested mark for one CA part.
     *
     * @param  string  $missing  'zero': work not done counts as 0; 'skip': left out of the average
     */
    public function suggest(int $schoolId, int $termId, int $classId, int $subjectId, string $component, array $homeworkIds, array $examIds, string $missing): array
    {
        $students = Student::where('school_id', $schoolId)->where('class_id', $classId)->where('status', 'active')->with('user:id,name')->get()
            ->sortBy(fn ($s) => $s->user?->name)->values();
        if ($students->isEmpty()) {
            throw new DomainRefusal('There are no students in this class.');
        }
        $part = $this->part($schoolId, $termId, (int) $students->first()->id, $component);

        $homework = Homework::where('school_id', $schoolId)->where('class_id', $classId)->where('subject_id', $subjectId)->whereIn('id', $homeworkIds ?: [0])->get();
        $exams = DB::table('cbt_exams')->where('school_id', $schoolId)->where('class_id', $classId)->where('subject_id', $subjectId)->whereIn('id', $examIds ?: [0])->get(['id', 'title']);
        if ($homework->count() !== count(array_unique($homeworkIds)) || $exams->count() !== count(array_unique($examIds))) {
            throw new DomainRefusal('Choose homework and quizzes set for this class and subject.');
        }
        if ($homework->isEmpty() && $exams->isEmpty()) {
            throw new DomainRefusal('Choose at least one homework or quiz.');
        }

        $subs = DB::table('homework_submissions')->whereIn('homework_id', $homework->pluck('id'))->get()->groupBy(fn ($r) => $r->homework_id.'|'.$r->student_id);
        $attempts = DB::table('cbt_attempts')->whereIn('exam_id', $exams->pluck('id'))->whereIn('status', ['graded', 'submitted'])->get()->groupBy(fn ($r) => $r->exam_id.'|'.$r->student_id);
        $current = ScoreEntry::where('school_id', $schoolId)->where('term_id', $termId)->where('subject_id', $subjectId)->whereIn('student_id', $students->pluck('id'))->pluck('component_scores', 'student_id');

        $rows = $students->map(function (Student $s) use ($homework, $exams, $subs, $attempts, $missing, $part, $current, $component) {
            $items = [];
            foreach ($homework as $h) {
                if (! $h->isFor($s)) {
                    continue; // not set for this student: neither done nor missed
                }
                $sub = $subs->get($h->id.'|'.$s->id)?->first();
                $out = $sub?->marks_awarded !== null ? ($sub->marks_available ?: $h->max_score) : null;
                $items[] = ['source' => "homework:{$h->id}", 'title' => $h->title,
                    'percent' => $sub && $sub->marks_awarded !== null && $out ? round($sub->marks_awarded / $out * 100, 1) : null,
                    'state' => ! $sub ? 'not_submitted' : ($sub->marks_awarded === null ? 'not_marked' : 'marked')];
            }
            foreach ($exams as $e) {
                $list = $attempts->get($e->id.'|'.$s->id) ?? collect();
                $best = $list->where('status', 'graded')->sortByDesc('percentage')->first();
                $items[] = ['source' => "quiz:{$e->id}", 'title' => $e->title,
                    'percent' => $best ? (float) $best->percentage : null,
                    'state' => $best ? 'marked' : ($list->isNotEmpty() ? 'not_marked' : 'not_submitted')];
            }

            // Work handed in but not yet marked is never counted as zero.
            $counted = collect($items)->filter(fn ($i) => $i['state'] === 'marked' || ($i['state'] === 'not_submitted' && $missing === 'zero'));
            $avg = $counted->isEmpty() ? null : $counted->avg(fn ($i) => $i['percent'] ?? 0);
            $marks = $current[$s->id] ?? null;
            $marks = is_string($marks) ? json_decode($marks, true) : $marks;

            return [
                'student_id' => $s->id, 'name' => $s->user?->name, 'admission_number' => $s->admission_number,
                'items' => $items, 'average_percent' => $avg !== null ? round($avg, 1) : null,
                'suggested' => $avg !== null ? round($avg * $part['max'] / 100, 1) : null,
                'current' => $marks[$component] ?? null,
                'unmarked' => collect($items)->where('state', 'not_marked')->count(),
            ];
        });

        return ['part' => $part, 'rows' => $rows->values()];
    }

    /**
     * Enter the teacher's marks. Each student goes through the score writer,
     * so locked results and out-of-range marks are refused per student.
     *
     * @param  array<int, array{student_id: int, score: float|null, suggested?: float|null}>  $rows
     */
    public function apply(int $schoolId, int $termId, int $classId, int $subjectId, string $component, array $rows, array $sources, int $userId): array
    {
        $studentIds = Student::where('school_id', $schoolId)->where('class_id', $classId)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $saved = [];
        $refused = [];

        foreach ($rows as $row) {
            if (! in_array((int) $row['student_id'], $studentIds, true)) {
                $refused[] = ['student_id' => (int) $row['student_id'], 'reason' => 'Not in this class.'];

                continue;
            }
            if ($row['score'] === null || $row['score'] === '') {
                continue;
            }
            $this->part($schoolId, $termId, (int) $row['student_id'], $component);
            try {
                $this->writer->write($schoolId, $termId, (int) $row['student_id'], $subjectId, ['components' => [$component => $row['score']]], null, false);
                $saved[] = ['student_id' => (int) $row['student_id'], 'suggested' => $row['suggested'] ?? null, 'final' => (float) $row['score']];
            } catch (DomainRefusal $e) {
                $refused[] = ['student_id' => (int) $row['student_id'], 'reason' => $e->getMessage()];
            } catch (ValidationException $e) {
                $refused[] = ['student_id' => (int) $row['student_id'], 'reason' => collect($e->errors())->flatten()->first()];
            }
        }

        if ($saved !== []) {
            app(\App\Services\GradingService::class)->recomputeClassPositions($schoolId, $classId, $termId);
            DB::table('ca_coursework_imports')->insert([
                'school_id' => $schoolId, 'term_id' => $termId, 'class_id' => $classId, 'subject_id' => $subjectId, 'component' => $component,
                'sources' => json_encode($sources), 'rows' => json_encode($saved),
                'adjusted_count' => collect($saved)->filter(fn ($r) => $r['suggested'] === null || abs($r['suggested'] - $r['final']) > 0.001)->count(),
                'user_id' => $userId, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return ['saved' => count($saved), 'refused' => $refused];
    }

    public function history(int $schoolId, int $termId, int $classId, int $subjectId): Collection
    {
        return DB::table('ca_coursework_imports as i')->leftJoin('users as u', 'u.id', '=', 'i.user_id')
            ->where('i.school_id', $schoolId)->where('i.term_id', $termId)->where('i.class_id', $classId)->where('i.subject_id', $subjectId)
            ->orderByDesc('i.id')->get(['i.id', 'i.component', 'i.sources', 'i.adjusted_count', 'i.rows', 'i.created_at', 'u.name as by'])
            ->map(fn ($r) => ['id' => $r->id, 'component' => $r->component, 'sources' => json_decode($r->sources, true), 'students' => count(json_decode($r->rows, true)),
                'adjusted' => (int) $r->adjusted_count, 'by' => $r->by, 'at' => $r->created_at]);
    }

    /** The CA part being filled. Exams are not filled from coursework. */
    private function part(int $schoolId, int $termId, int $studentId, string $component): array
    {
        $part = collect($this->writer->schemeFor($schoolId, $termId, $studentId)->parts())->firstWhere('key', $component);
        if (! $part) {
            throw new DomainRefusal("'{$component}' is not part of this class's marking scheme.");
        }
        if (($part['kind'] ?? 'ca') !== 'ca') {
            throw new DomainRefusal('Only a CA can be filled from coursework; the exam is entered directly.');
        }

        return $part;
    }
}
