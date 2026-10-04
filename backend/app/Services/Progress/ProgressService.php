<?php

namespace App\Services\Progress;

use App\Models\Broadsheet;
use App\Models\Intervention;
use App\Models\ProgressSetting;
use App\Models\ResultRelease;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentProgressInsight;
use App\Models\Term;
use App\Models\TermSnapshot;
use App\Services\Results\ResultCalculator;
use App\Support\CurrentTerm;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Student progress and analysis (TASK: Student Progress and Analysis).
 *
 * Read-only on academic data. Every figure comes from one of two places:
 *  - a past term: its locked snapshot, exactly as issued, never recomputed;
 *  - the current term: the central ResultCalculator, marked provisional.
 * Neither reimplements a calculation, so a figure here is the figure on the
 * report card (§3 rule 3).
 *
 * Changes are in percentage points. Original marks are always kept beside
 * any percentage. Insights describe what the records show and never claim a
 * cause; each is stored with its rule and inputs.
 */
class ProgressService
{
    public function __construct(private ResultCalculator $calculator)
    {
    }

    // ==================================================================
    // Terms: the series behind every trend
    // ==================================================================

    /**
     * Every term on record for the student, oldest first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function series(Student $student, bool $publishedOnly = false, bool $includeCurrent = true): Collection
    {
        $schoolId = (int) $student->school_id;

        $snapshots = TermSnapshot::withoutGlobalScopes()
            ->where('term_snapshots.school_id', $schoolId)
            ->where('term_snapshots.student_id', $student->id)
            ->whereNull('term_snapshots.superseded_at')
            ->leftJoin('terms', 'terms.id', '=', 'term_snapshots.term_id')
            ->orderByRaw('terms.start_date IS NULL')
            ->orderBy('terms.start_date')
            ->orderBy('term_snapshots.id')
            ->select('term_snapshots.*', 'terms.start_date as term_start')
            ->get();

        $points = $snapshots
            ->filter(fn ($s) => ! $publishedOnly || ($s->term_id && ResultRelease::isReleasedFor($schoolId, (int) $s->term_id, $s->class_id ? (int) $s->class_id : null)))
            ->map(function (TermSnapshot $s) {
                $f = $s->figures();

                return [
                    'term_id' => $s->term_id,
                    'label' => trim("{$s->session_name} {$s->term_name}"),
                    'session' => $s->session_name,
                    'term' => $s->term_name,
                    'class' => $s->class_name,
                    'class_id' => $s->class_id,
                    'average' => $f['average'] ?? null,
                    'class_average' => $f['class_average'] ?? null,
                    'position' => $f['position'] ?? null,
                    'class_size' => $f['class_size'] ?? null,
                    'subjects' => $f['subjects'] ?? [],
                    'attendance' => $f['attendance'] ?? null,
                    'pass_mark' => $f['pass_mark'] ?? 40,
                    'scale_signature' => md5(json_encode($f['grading_scale'] ?? null)),
                    'provisional' => false,
                    'source' => $s->source,
                    'provenance' => $s->provenance,
                ];
            })->values();

        if ($includeCurrent && ! $publishedOnly) {
            $current = CurrentTerm::for($schoolId);

            if ($current && ! $points->contains('term_id', $current->id) && $student->class_id) {
                $calc = $this->calculator->forClass($schoolId, $current->id, (int) $student->class_id);
                $row = collect($calc['students'])->firstWhere('id', $student->id);

                if ($row && $row['subjects_counted'] > 0) {
                    $points->push($this->pointFromCalc($calc, $row, true));
                }
            }
        }

        // Where a period used a different grading scale, say so on the chart (§5.4).
        $previous = null;

        return $points->map(function ($p) use (&$previous) {
            $p['scale_changed'] = $previous !== null && $previous !== $p['scale_signature'];
            $previous = $p['scale_signature'];
            unset($p['scale_signature']);

            return $p;
        })->values();
    }

    private function pointFromCalc(array $calc, array $row, bool $provisional): array
    {
        $subjects = [];

        foreach ($calc['subjects'] as $subject) {
            $r = $row['results'][$subject['id']] ?? null;

            if (! $r || $r['state'] === 'not_offered') {
                continue;
            }

            $subjects[] = [
                'subject_id' => $subject['id'],
                'subject' => $subject['name'],
                'state' => $r['state'],
                'components' => $r['components'],
                'total' => $r['total'],
                'grade' => $r['grade'],
                'remark' => $r['remark'],
                'position' => $r['position'],
                'class_average' => $calc['subject_stats'][$subject['id']]['average'] ?? null,
            ];
        }

        return [
            'term_id' => $calc['term']['id'],
            'label' => trim(($calc['term']['session'] ?? '').' '.$calc['term']['name']),
            'session' => $calc['term']['session'],
            'term' => $calc['term']['name'],
            'class' => $calc['class']['name'],
            'class_id' => $calc['class']['id'],
            'average' => $row['average'],
            'class_average' => $calc['class_stats']['class_average'],
            'position' => $row['position'],
            'class_size' => count($calc['students']),
            'subjects' => $subjects,
            'attendance' => $row['attendance'] ?? null,
            'pass_mark' => $calc['config']['pass_mark'],
            'scale_signature' => md5(json_encode($calc['grading_bands'])),
            'provisional' => $provisional,
            'source' => 'live',
            'provenance' => 'system_generated',
            'parts' => $calc['scheme']['parts'],
        ];
    }

    /**
     * The overview and subject table for one term (or the latest).
     *
     * @param  array<int, int>|null  $subjectScope  subject ids a subject teacher may see detail for; null = all
     */
    public function summary(Student $student, ?int $termId, ?array $subjectScope, bool $publishedOnly = false): array
    {
        $series = $this->series($student, $publishedOnly);
        $index = $termId ? $series->search(fn ($p) => $p['term_id'] === $termId) : $series->keys()->last();
        $point = $index !== false && $index !== null ? $series[$index] : null;
        $previous = $point && $index > 0 ? $series[$index - 1] : null;

        if (! $point) {
            return ['period' => null, 'overview' => null, 'subjects' => [], 'trend' => $series->values(), 'empty' => true];
        }

        $prevBySubject = collect($previous['subjects'] ?? [])->keyBy(fn ($s) => mb_strtolower($s['subject']));
        $passMark = (float) $point['pass_mark'];
        $parts = $point['parts'] ?? null;

        $subjects = collect($point['subjects'])->map(function ($s) use ($prevBySubject, $subjectScope, $parts) {
            $prev = $prevBySubject->get(mb_strtolower($s['subject']));
            $detail = $subjectScope === null || in_array((int) ($s['subject_id'] ?? 0), $subjectScope, true);
            [$ca, $exam] = $this->caAndExam($s['components'] ?? [], $parts);

            return [
                'subject_id' => $s['subject_id'] ?? null,
                'subject' => $s['subject'],
                'state' => $s['state'],
                'ca' => $ca,
                'exam' => $exam,
                'total' => $s['total'],
                'grade' => $s['grade'],
                'previous_total' => $prev['total'] ?? null,
                'change' => isset($prev['total'], $s['total']) ? round($s['total'] - $prev['total'], 2) : null,
                // Class-relative figures only for subjects the teacher teaches (PRD §3.1).
                'class_average' => $detail ? ($s['class_average'] ?? null) : null,
                'gap' => $detail && isset($s['class_average'], $s['total']) ? round($s['total'] - $s['class_average'], 2) : null,
                'position' => $detail ? ($s['position'] ?? null) : null,
            ];
        })->values();

        $scored = $subjects->where('state', 'scored');

        return [
            'period' => ['term_id' => $point['term_id'], 'label' => $point['label'], 'class' => $point['class'], 'provisional' => $point['provisional']],
            'overview' => [
                'average' => $point['average'],
                'previous_average' => $previous['average'] ?? null,
                'change_points' => isset($previous['average'], $point['average']) ? round($point['average'] - $previous['average'], 2) : null,
                'subjects_passed' => $scored->filter(fn ($s) => $s['total'] >= $passMark)->count(),
                'subjects_counted' => $scored->count(),
                'pass_mark' => $passMark,
                'attendance_rate' => $point['attendance']['rate'] ?? null,
                'position' => $point['position'],
                'class_size' => $point['class_size'],
                'provisional' => $point['provisional'],
            ],
            'subjects' => $subjects,
            'trend' => $series->map(fn ($p) => array_intersect_key($p, array_flip(['term_id', 'label', 'average', 'class_average', 'provisional', 'scale_changed'])))->values(),
            'empty' => false,
        ];
    }

    /** CA total and exam total from a set of part marks. */
    private function caAndExam(?array $components, ?array $parts): array
    {
        if (! $components) {
            return [null, null];
        }

        $kinds = collect($parts ?? [])->pluck('kind', 'key');
        $ca = 0.0;
        $exam = 0.0;

        foreach ($components as $key => $mark) {
            $kind = $kinds[$key] ?? (str_starts_with((string) $key, 'exam') ? 'exam' : 'ca');
            $kind === 'exam' ? $exam += (float) $mark : $ca += (float) $mark;
        }

        return [round($ca, 2), round($exam, 2)];
    }

    /** One subject over time, for the subject detail panel (§5.5). */
    public function subjectHistory(Student $student, string $subjectName, bool $publishedOnly = false): array
    {
        return $this->series($student, $publishedOnly)->map(function ($p) use ($subjectName) {
            $s = collect($p['subjects'])->first(fn ($x) => mb_strtolower($x['subject']) === mb_strtolower($subjectName));

            return ['label' => $p['label'], 'total' => $s['total'] ?? null, 'grade' => $s['grade'] ?? null, 'components' => $s['components'] ?? null, 'provisional' => $p['provisional']];
        })->filter(fn ($r) => $r['total'] !== null)->values()->all();
    }

    // ==================================================================
    // Assessments, topics, attendance
    // ==================================================================

    /**
     * CBT attempts and graded homework, with the original marks beside the
     * percentage, and completion counts (§5.7).
     */
    public function assessments(Student $student, ?int $subjectId = null, ?string $from = null, ?string $to = null): array
    {
        $schoolId = (int) $student->school_id;

        $cbt = DB::table('cbt_attempts as a')
            ->join('cbt_exams as e', 'e.id', '=', 'a.exam_id')
            ->leftJoin('subjects as s', 's.id', '=', 'e.subject_id')
            ->where('a.school_id', $schoolId)
            ->where('a.student_id', $student->id)
            ->whereIn('a.status', ['submitted', 'graded'])
            ->when($subjectId, fn ($q) => $q->where('e.subject_id', $subjectId))
            ->when($from, fn ($q) => $q->where('a.submitted_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('a.submitted_at', '<=', $to.' 23:59:59'))
            ->get(['a.id', 'e.title', 's.name as subject', 'e.subject_id', 'a.submitted_at', 'a.raw_score', 'a.max_score', 'a.percentage', 'a.status', 'a.requires_manual_grading'])
            ->map(fn ($r) => [
                'kind' => 'cbt',
                'id' => (int) $r->id,
                'title' => $r->title,
                'subject' => $r->subject,
                'subject_id' => $r->subject_id ? (int) $r->subject_id : null,
                'date' => $r->submitted_at ? substr((string) $r->submitted_at, 0, 10) : null,
                'score' => $r->raw_score !== null ? (float) $r->raw_score : null,
                'total' => $r->max_score !== null ? (float) $r->max_score : null,
                'percentage' => $r->percentage !== null ? (float) $r->percentage : null,
                'awaiting_grading' => (bool) $r->requires_manual_grading && $r->status !== 'graded',
            ]);

        $homework = DB::table('homework_submissions as hs')
            ->join('homework as h', 'h.id', '=', 'hs.homework_id')
            ->leftJoin('subjects as s', 's.id', '=', 'h.subject_id')
            ->where('hs.school_id', $schoolId)
            ->where('hs.student_id', $student->id)
            ->when($subjectId, fn ($q) => $q->where('h.subject_id', $subjectId))
            ->when($from, fn ($q) => $q->where('hs.submitted_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('hs.submitted_at', '<=', $to.' 23:59:59'))
            ->get(['hs.id', 'h.title', 's.name as subject', 'h.subject_id', 'hs.submitted_at', 'hs.marks_awarded', 'hs.marks_available', 'hs.status', 'hs.teacher_feedback'])
            ->map(fn ($r) => [
                'kind' => 'assignment',
                'id' => (int) $r->id,
                'title' => $r->title,
                'subject' => $r->subject,
                'subject_id' => $r->subject_id ? (int) $r->subject_id : null,
                'date' => $r->submitted_at ? substr((string) $r->submitted_at, 0, 10) : null,
                'score' => $r->marks_awarded !== null ? (float) $r->marks_awarded : null,
                'total' => $r->marks_available !== null ? (float) $r->marks_available : null,
                'percentage' => $r->marks_awarded !== null && (float) $r->marks_available > 0 ? round($r->marks_awarded / $r->marks_available * 100, 1) : null,
                'awaiting_grading' => $r->status === 'submitted',
                'feedback' => $r->teacher_feedback,
            ]);

        $history = $cbt->concat($homework)->sortBy('date')->values();

        return ['history' => $history, 'completion' => $this->completion($student, $subjectId)];
    }

    /**
     * Assigned, completed, missed, pending and awaiting grading. An
     * assessment is missed only once its deadline has passed (§4).
     */
    public function completion(Student $student, ?int $subjectId = null): array
    {
        $schoolId = (int) $student->school_id;
        $term = CurrentTerm::for($schoolId);
        $from = $term?->start_date?->toDateString() ?? now()->subMonths(4)->toDateString();
        $now = now();

        $exams = DB::table('cbt_exams')->where('school_id', $schoolId)->where('class_id', $student->class_id)
            ->whereIn('status', ['published', 'closed'])
            ->when($subjectId, fn ($q) => $q->where('subject_id', $subjectId))
            ->where(fn ($q) => $q->whereNull('opens_at')->orWhere('opens_at', '>=', $from))
            ->get(['id', 'closes_at']);

        $attempts = DB::table('cbt_attempts')->where('student_id', $student->id)->whereIn('exam_id', $exams->pluck('id') ?: [0])
            ->whereIn('status', ['submitted', 'graded'])->get(['exam_id', 'status', 'requires_manual_grading'])->keyBy('exam_id');

        $homework = DB::table('homework')->where('school_id', $schoolId)->where('class_id', $student->class_id)
            ->when($subjectId, fn ($q) => $q->where('subject_id', $subjectId))
            ->where('created_at', '>=', $from)
            ->get(['id', 'due_date']);

        $submissions = DB::table('homework_submissions')->where('student_id', $student->id)->whereIn('homework_id', $homework->pluck('id') ?: [0])
            ->get(['homework_id', 'status'])->keyBy('homework_id');

        $c = ['assigned' => 0, 'completed' => 0, 'missed' => 0, 'pending' => 0, 'awaiting_grading' => 0];

        foreach ($exams as $exam) {
            $c['assigned']++;
            $a = $attempts->get($exam->id);

            if ($a) {
                $c['completed']++;
                if ($a->requires_manual_grading && $a->status !== 'graded') {
                    $c['awaiting_grading']++;
                }
            } elseif ($exam->closes_at && $now->gt($exam->closes_at)) {
                $c['missed']++;
            } else {
                $c['pending']++;
            }
        }

        foreach ($homework as $h) {
            $c['assigned']++;
            $s = $submissions->get($h->id);

            if ($s) {
                $c['completed']++;
                if ($s->status === 'submitted') {
                    $c['awaiting_grading']++;
                }
            } elseif ($h->due_date && $now->gt(substr((string) $h->due_date, 0, 10).' 23:59:59')) {
                $c['missed']++;
            } else {
                $c['pending']++;
            }
        }

        $c['rate'] = $c['assigned'] > 0 ? round($c['completed'] / $c['assigned'] * 100, 1) : null;

        return $c;
    }

    /**
     * Performance by topic from tagged CBT questions (§5.6). A topic with
     * fewer questions than the school's minimum is "insufficient data", never
     * a rating, and suggestions come only from the school's own materials.
     */
    public function topics(Student $student, ?int $subjectId = null): array
    {
        $settings = ProgressSetting::for((int) $student->school_id);

        $rows = DB::table('cbt_attempt_answers as aa')
            ->join('cbt_attempts as a', 'a.id', '=', 'aa.attempt_id')
            ->join('question_bank as q', 'q.id', '=', 'aa.question_id')
            ->leftJoin('subjects as s', 's.id', '=', 'q.subject_id')
            ->where('a.student_id', $student->id)
            ->whereIn('a.status', ['submitted', 'graded'])
            ->whereNotNull('q.topic')
            ->where('q.topic', '!=', '')
            ->whereNotNull('aa.awarded_marks')
            ->when($subjectId, fn ($q) => $q->where('q.subject_id', $subjectId))
            ->selectRaw('q.subject_id, s.name as subject, q.topic, COUNT(*) as questions, SUM(aa.awarded_marks) as awarded, SUM(q.marks) as available')
            ->groupBy('q.subject_id', 's.name', 'q.topic')
            ->get();

        $materials = \Illuminate\Support\Facades\Schema::hasTable('learning_materials')
            ? DB::table('learning_materials')->where('school_id', $student->school_id)->where('status', 'published')->whereNotNull('topic')->get(['id', 'title', 'topic', 'subject_id'])
            : collect();

        return $rows->map(function ($r) use ($settings, $materials) {
            $pct = (float) $r->available > 0 ? round($r->awarded / $r->available * 100, 1) : null;
            $enough = (int) $r->questions >= $settings->topic_min_questions;

            $category = ! $enough || $pct === null ? 'insufficient_data'
                : ($pct >= $settings->topic_strong ? 'strong' : ($pct >= $settings->topic_developing ? 'developing' : 'needs_attention'));

            $suggestions = $category === 'needs_attention'
                ? $materials->filter(fn ($m) => (int) $m->subject_id === (int) $r->subject_id && mb_strtolower(trim($m->topic)) === mb_strtolower(trim($r->topic)))
                    ->map(fn ($m) => ['id' => $m->id, 'title' => $m->title])->values()->all()
                : [];

            return [
                'subject_id' => (int) $r->subject_id,
                'subject' => $r->subject,
                'topic' => $r->topic,
                'questions' => (int) $r->questions,
                'percentage' => $enough ? $pct : null,
                'category' => $category,
                'suggested_materials' => $suggestions,
            ];
        })->sortBy(['subject', 'topic'])->values()->all();
    }

    public function attendance(Student $student, ?int $termId = null): array
    {
        $rows = DB::table('attendance_records')
            ->where('school_id', $student->school_id)
            ->where('student_id', $student->id)
            ->when($termId, fn ($q) => $q->where('term_id', $termId))
            ->get(['date', 'status']);

        $totals = ['days' => $rows->count(), 'present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0];
        $months = [];

        foreach ($rows as $r) {
            $totals[$r->status] = ($totals[$r->status] ?? 0) + 1;
            $m = substr((string) $r->date, 0, 7);
            $months[$m] ??= ['month' => $m, 'present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0];
            $months[$m][$r->status]++;
        }

        ksort($months);

        $rate = fn ($c) => ($days = $c['present'] + $c['absent'] + $c['late'] + $c['excused']) > 0
            ? round(($c['present'] + $c['late']) / $days * 100, 1) : null;

        return [
            'totals' => $totals + ['rate' => $rate($totals)],
            'monthly' => array_values(array_map(fn ($m) => $m + ['rate' => $rate($m)], $months)),
            'note' => 'Attendance and results are shown side by side as an observed pattern. One does not prove the other.',
        ];
    }

    // ==================================================================
    // Insights and early-warning flags (§6)
    // ==================================================================

    /**
     * Run the rules, store what they found with their inputs, return them.
     * Deterministic: the same records always give the same insights.
     */
    public function insights(Student $student): array
    {
        $settings = ProgressSetting::for((int) $student->school_id);
        $series = $this->series($student);
        $current = $series->last();
        $previous = $series->count() > 1 ? $series[$series->count() - 2] : null;
        $found = [];

        $add = function (string $rule, string $category, string $severity, string $text, array $inputs, array $evidence = []) use (&$found) {
            $found[] = compact('rule', 'category', 'severity', 'text', 'inputs', 'evidence');
        };

        if ($current && $previous && $current['average'] !== null && $previous['average'] !== null) {
            $change = round($current['average'] - $previous['average'], 1);

            if ($change >= $settings->trend_points) {
                $add('average_rise', 'trend', 'info', "Overall average rose by {$change} points compared with {$previous['label']}.", ['current' => $current['average'], 'previous' => $previous['average']], [$current['label'], $previous['label']]);
            } elseif ($change <= -$settings->sharp_decline_points) {
                $add('sharp_decline', 'risk', 'high', 'Overall average fell by '.abs($change)." points compared with {$previous['label']}.", ['current' => $current['average'], 'previous' => $previous['average'], 'threshold' => $settings->sharp_decline_points], [$current['label'], $previous['label']]);
            } elseif ($change <= -$settings->trend_points) {
                $add('average_fall', 'trend', 'low', 'Overall average fell by '.abs($change).' points.', ['current' => $current['average'], 'previous' => $previous['average']]);
            }
        }

        // Per-subject trends over the last three terms.
        $last3 = $series->slice(-3)->values();

        if ($last3->count() === 3) {
            $names = collect($last3->last()['subjects'])->pluck('subject');

            foreach ($names as $name) {
                $vals = $last3->map(fn ($p) => collect($p['subjects'])->first(fn ($s) => mb_strtolower($s['subject']) === mb_strtolower($name))['total'] ?? null);

                if ($vals->contains(null)) {
                    continue;
                }

                if ($vals[0] > $vals[1] && $vals[1] > $vals[2]) {
                    $add('subject_decline_three', 'weakness', 'medium', "{$name} scores fell over the last three terms ({$vals[0]}, {$vals[1]}, {$vals[2]}).", ['subject' => $name, 'scores' => $vals->all()], $last3->pluck('label')->all());
                } elseif ($vals->max() - $vals->min() <= $settings->steady_range) {
                    $add('subject_steady', 'strength', 'info', "{$name} scores stayed within a {$settings->steady_range}-point range across the last three terms.", ['subject' => $name, 'scores' => $vals->all()]);
                }
            }
        }

        if ($current) {
            $passMark = (float) $current['pass_mark'];
            $scored = collect($current['subjects'])->where('state', 'scored');
            $failed = $scored->filter(fn ($s) => $s['total'] < $passMark);
            $core = array_map('intval', (array) ($settings->core_subject_ids ?? []));

            foreach ($failed as $s) {
                if (in_array((int) ($s['subject_id'] ?? 0), $core, true)) {
                    $add('failed_core', 'risk', 'high', "{$s['subject']} is below the pass mark ({$s['total']} against {$passMark}).", ['subject' => $s['subject'], 'total' => $s['total'], 'pass_mark' => $passMark], [$current['label']]);
                }
            }

            if ($failed->count() >= $settings->multiple_failed_subjects) {
                $add('multiple_failed', 'risk', 'high', $failed->count().' subjects are below the pass mark this term.', ['subjects' => $failed->pluck('subject')->all(), 'pass_mark' => $passMark, 'threshold' => $settings->multiple_failed_subjects], [$current['label']]);
            }

            $best = $scored->sortByDesc('total')->first();
            if ($best && $best['total'] >= 70) {
                $add('top_subject', 'strength', 'info', "Highest result this term: {$best['subject']} ({$best['total']}).", ['subject' => $best['subject'], 'total' => $best['total']]);
            }

            $rate = $current['attendance']['rate'] ?? null;
            if ($rate !== null && $rate < $settings->low_attendance_rate) {
                $add('low_attendance', 'risk', 'medium', "Attendance is {$rate}%, below the school's {$settings->low_attendance_rate}% threshold.", ['rate' => $rate, 'threshold' => $settings->low_attendance_rate], [$current['label']]);
            }
        }

        $completion = $this->completion($student);
        if ($completion['missed'] >= 3) {
            $add('incomplete_assessments', 'risk', 'medium', "{$completion['missed']} assigned assessments were not completed by their deadlines.", $completion);
        }
        if ($completion['rate'] !== null && $completion['assigned'] >= 5 && $completion['rate'] < $settings->low_completion_rate) {
            $add('low_completion', 'risk', 'medium', "{$completion['rate']}% of assigned assessments are completed, below the {$settings->low_completion_rate}% threshold.", $completion);
        }

        foreach ($this->topics($student) as $t) {
            if ($t['category'] === 'needs_attention') {
                $add('topic_below_threshold', 'weakness', 'low', "{$t['subject']}: {$t['topic']} results are below the school's threshold ({$t['percentage']}% over {$t['questions']} questions).", $t);
            }
        }

        // Stored with their inputs, replacing this term's earlier run.
        $termId = $current['term_id'] ?? null;

        DB::transaction(function () use ($student, $termId, $found) {
            StudentProgressInsight::withoutGlobalScopes()->where('student_id', $student->id)->where('term_id', $termId)->delete();

            foreach ($found as $f) {
                StudentProgressInsight::withoutGlobalScopes()->create([
                    'school_id' => $student->school_id,
                    'student_id' => $student->id,
                    'term_id' => $termId,
                    'rule' => $f['rule'],
                    'category' => $f['category'],
                    'severity' => $f['severity'],
                    'text' => $f['text'],
                    'inputs' => $f['inputs'],
                    'evidence' => $f['evidence'],
                    'generated_at' => now(),
                ]);
            }
        });

        $order = ['high' => 0, 'medium' => 1, 'low' => 2, 'info' => 3];
        $risks = collect($found)->where('category', 'risk')->sortBy(fn ($f) => $order[$f['severity']])->values();

        return [
            'insights' => collect($found)->where('category', '!=', 'risk')->values()->all(),
            // Capped so alerts stay meaningful (§6.2).
            'flags' => $risks->take($settings->max_flags_shown)->all(),
            'flags_total' => $risks->count(),
            'label' => 'Automated: generated from recorded results by fixed rules. They describe the records and do not explain causes.',
        ];
    }

    // ==================================================================
    // Class and school views (§8)
    // ==================================================================

    /** Approved figures where the class has them, else live and provisional. */
    public function classCalc(int $schoolId, int $termId, int $classId): array
    {
        $bs = Broadsheet::withoutGlobalScopes()->where('school_id', $schoolId)->where('term_id', $termId)->where('class_id', $classId)->first();
        $version = $bs?->isLocked() ? $bs->latestVersion() : null;

        $calc = $version ? $version->data()['calculation'] : $this->calculator->forClass($schoolId, $termId, $classId);
        $calc['provisional'] = $version === null;

        return $calc;
    }

    /**
     * @param  array<int, int>|null  $subjectScope  null = every subject
     */
    public function classAnalysis(int $schoolId, int $termId, int $classId, ?array $subjectScope = null): array
    {
        $settings = ProgressSetting::for($schoolId);
        $calc = $this->classCalc($schoolId, $termId, $classId);
        $term = Term::withoutGlobalScopes()->find($termId);

        $previousTerm = Term::withoutGlobalScopes()->where('school_id', $schoolId)->where('start_date', '<', $term->start_date->toDateString())->orderByDesc('start_date')->first();
        $previous = $previousTerm ? $this->classCalc($schoolId, $previousTerm->id, $classId) : null;
        $prevAverages = collect($previous['students'] ?? [])->pluck('average', 'id');

        $subjects = collect($calc['subjects'])->filter(fn ($s) => $subjectScope === null || in_array($s['id'], $subjectScope, true))->values();
        $passMark = (float) $calc['config']['pass_mark'];
        $core = array_map('intval', (array) ($settings->core_subject_ids ?? []));

        $students = collect($calc['students'])->map(function ($s) use ($subjects, $prevAverages, $passMark, $core, $settings) {
            $failed = [];
            foreach ($s['results'] as $subjectId => $r) {
                if ($r['state'] === 'scored' && $r['total'] < $passMark) {
                    $failed[] = (int) $subjectId;
                }
            }

            $prev = $prevAverages->get($s['id']);
            $change = $prev !== null && $s['average'] !== null ? round($s['average'] - $prev, 2) : null;
            $flags = [];

            if (array_intersect($failed, $core) !== []) {
                $flags[] = 'Failed a core subject';
            }
            if (count($failed) >= $settings->multiple_failed_subjects) {
                $flags[] = count($failed).' subjects below the pass mark';
            }
            if ($change !== null && $change <= -$settings->sharp_decline_points) {
                $flags[] = 'Average fell '.abs($change).' points';
            }
            if (($s['attendance']['rate'] ?? 100) < $settings->low_attendance_rate) {
                $flags[] = 'Attendance '.$s['attendance']['rate'].'%';
            }

            return [
                'id' => $s['id'],
                'name' => $s['name'],
                'admission_number' => $s['admission_number'],
                'scores' => $subjects->mapWithKeys(fn ($sub) => [$sub['id'] => $s['results'][$sub['id']]['total'] ?? null])->all(),
                'average' => $s['average'],
                'change' => $change,
                'position' => $s['position'],
                'attendance_rate' => $s['attendance']['rate'] ?? null,
                'flags' => $flags,
            ];
        });

        $parts = collect($calc['scheme']['parts']);
        $caMax = $parts->where('kind', 'ca')->sum('max');
        $examMax = $parts->where('kind', 'exam')->sum('max');

        $subjectStats = $subjects->map(function ($sub) use ($calc, $parts, $caMax, $examMax) {
            $stats = $calc['subject_stats'][$sub['id']];
            $caPct = [];
            $examPct = [];
            $buckets = ['0-39' => 0, '40-49' => 0, '50-59' => 0, '60-69' => 0, '70-79' => 0, '80-100' => 0];

            foreach ($calc['students'] as $s) {
                $r = $s['results'][$sub['id']] ?? null;
                if (! $r || $r['state'] !== 'scored') {
                    continue;
                }

                $t = (float) $r['total'];
                $key = match (true) { $t < 40 => '0-39', $t < 50 => '40-49', $t < 60 => '50-59', $t < 70 => '60-69', $t < 80 => '70-79', default => '80-100' };
                $buckets[$key]++;

                $ca = $parts->where('kind', 'ca')->sum(fn ($p) => (float) ($r['components'][$p['key']] ?? 0));
                $exam = $parts->where('kind', 'exam')->sum(fn ($p) => (float) ($r['components'][$p['key']] ?? 0));
                if ($caMax > 0) {
                    $caPct[] = $ca / $caMax * 100;
                }
                if ($examMax > 0) {
                    $examPct[] = $exam / $examMax * 100;
                }
            }

            $caAvg = $caPct ? round(array_sum($caPct) / count($caPct), 1) : null;
            $examAvg = $examPct ? round(array_sum($examPct) / count($examPct), 1) : null;

            return $stats + [
                'distribution' => $buckets,
                'ca_average_pct' => $caAvg,
                'exam_average_pct' => $examAvg,
                // CA running well above the exam can point to inflated CA (§8.2).
                'ca_exam_gap' => $caAvg !== null && $examAvg !== null ? round($caAvg - $examAvg, 1) : null,
            ];
        })->values();

        return [
            'class' => $calc['class'],
            'term' => $calc['term'],
            'provisional' => $calc['provisional'],
            'subjects' => $subjects->values(),
            'class_stats' => $calc['class_stats'],
            'subject_stats' => $subjectStats,
            'students' => $students->values(),
            'top' => $students->whereNotNull('average')->sortByDesc('average')->take(5)->values(),
            'bottom' => $students->whereNotNull('average')->sortBy('average')->take(5)->values(),
            'most_improved' => $students->whereNotNull('change')->sortByDesc('change')->take(5)->values(),
            'most_declined' => $students->whereNotNull('change')->sortBy('change')->take(5)->values(),
            'at_risk' => $students->filter(fn ($s) => $s['flags'] !== [])->values(),
        ];
    }

    public function schoolAnalytics(int $schoolId, int $termId, int $topCount = 5): array
    {
        $classes = SchoolClass::withoutGlobalScopes()->where('school_id', $schoolId)->orderBy('order_index')->orderBy('name')->get();
        $classRows = [];
        $subjectAgg = [];
        $everyone = collect();
        $atRisk = 0;

        foreach ($classes as $class) {
            $analysis = $this->classAnalysis($schoolId, $termId, $class->id);

            if ($analysis['students']->isEmpty()) {
                continue;
            }

            $classRows[] = [
                'class_id' => $class->id,
                'class' => $class->name,
                'students' => $analysis['class_stats']['students'],
                'average' => $analysis['class_stats']['class_average'],
                'pass_rate' => $analysis['class_stats']['with_results'] > 0 ? round($analysis['class_stats']['pass_count'] / $analysis['class_stats']['with_results'] * 100, 1) : null,
                'at_risk' => $analysis['at_risk']->count(),
                'provisional' => $analysis['provisional'],
                'subject_averages' => collect($analysis['subject_stats'])->pluck('average', 'subject')->all(),
            ];

            foreach ($analysis['subject_stats'] as $s) {
                $subjectAgg[$s['subject']] ??= ['entered' => 0, 'passed' => 0];
                $subjectAgg[$s['subject']]['entered'] += $s['entered'];
                $subjectAgg[$s['subject']]['passed'] += $s['pass_count'];
            }

            $atRisk += $analysis['at_risk']->count();
            $everyone = $everyone->concat($analysis['students']->map(fn ($s) => $s + ['class' => $class->name]));
        }

        return [
            'classes' => $classRows,
            'subjects' => collect($subjectAgg)->map(fn ($v, $name) => ['subject' => $name, 'entered' => $v['entered'], 'pass_rate' => $v['entered'] > 0 ? round($v['passed'] / $v['entered'] * 100, 1) : null])->sortBy('pass_rate')->values(),
            'top' => $everyone->whereNotNull('average')->sortByDesc('average')->take($topCount)->values(),
            'bottom' => $everyone->whereNotNull('average')->sortBy('average')->take($topCount)->values(),
            'at_risk_total' => $atRisk,
            'interventions' => Intervention::withoutGlobalScopes()->where('school_id', $schoolId)->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status'),
            'note' => 'Comparisons describe this term\'s records. They are not judgements of classes or teachers.',
        ];
    }
}
