<?php

namespace App\Services\Results;

use App\Models\BroadsheetConfiguration;
use App\Models\CaScheme;
use App\Models\GradingScale;
use App\Models\SchoolClass;
use App\Models\Term;
use App\Services\Academics\SubjectOffering;
use App\Support\Sections;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The one place a class's term results are calculated.
 *
 * Broadsheet spec §6: "a centralized calculation engine rather than
 * independently calculating results in each screen". The broadsheet, class
 * positions written for report cards, the snapshot frozen at approval and the
 * analytics all read from here, so a figure cannot differ between screens.
 *
 * Rules it holds, each from the spec:
 *  - Only students enrolled in the class for the term's session appear.
 *  - Only subjects that apply to a student count for them: compulsory class
 *    subjects for everyone in a stream that takes them, electives only for
 *    students taking them.
 *  - A missing score is never zero. It is "not entered" and marks the student
 *    incomplete. An absence counts as zero only if the school's policy says so.
 *  - Ranking follows the school's basis, method and scope, within the whole
 *    class, each arm or each stream as the school chooses.
 *  - Grades come from the school's scale for the class level, at the time of
 *    calculation; an approved snapshot freezes them from then on.
 */
class ResultCalculator
{
    /**
     * @param  array<string, mixed>|null  $config  override (tests, previews)
     * @return array<string, mixed>
     */
    public function forClass(int $schoolId, int $termId, int $classId, array $options = []): array
    {
        $withAttendance = $options['attendance'] ?? true;

        $term = Term::withoutGlobalScopes()->where('school_id', $schoolId)->with('session')->findOrFail($termId);
        $class = SchoolClass::withoutGlobalScopes()->where('school_id', $schoolId)->findOrFail($classId);
        $levelGroup = Sections::levelGroupOf($class->level_category);

        $config = $options['config'] ?? BroadsheetConfiguration::resolve($schoolId, $levelGroup);
        $scheme = CaScheme::forTerm($schoolId, $termId, $levelGroup);
        $bands = GradingScale::bandsFor($schoolId, $class->level_category);

        $roster = $this->roster($schoolId, $term, $classId);
        $studentIds = $roster->pluck('id')->all();

        $entries = DB::table('score_entries')
            ->where('school_id', $schoolId)
            ->where('term_id', $termId)
            ->whereIn('student_id', $studentIds ?: [0])
            ->whereNull('deleted_at')
            ->get();

        $entriesByStudent = $entries->groupBy('student_id');

        [$subjects, $applicable] = $this->subjects($schoolId, $termId, $classId, $roster, $entries);

        $subjects = $this->ordered($subjects, $config['subject_order'] ?? null);

        $students = [];

        foreach ($roster as $student) {
            $results = [];
            $sum = 0.0;
            $counted = 0;
            $missing = 0;

            $studentEntries = ($entriesByStudent->get($student->id) ?? collect())->keyBy('subject_id');

            foreach ($subjects as $subject) {
                $applies = in_array($subject['id'], $applicable[$student->id] ?? [], true);
                $entry = $studentEntries->get($subject['id']);

                $result = $this->resultFor($entry, $applies, $config, $bands, $scheme);

                if ($result['state'] === 'not_entered') {
                    $missing++;
                }

                if ($result['counted']) {
                    $sum += $result['counted_value'];
                    $counted++;
                }

                $results[$subject['id']] = $result;
            }

            $average = $counted > 0 ? $sum / $counted : null;

            $students[] = [
                'id' => (int) $student->id,
                'name' => $student->name,
                'admission_number' => $student->admission_number,
                'gender' => $student->gender,
                'arm_id' => $student->arm_id ? (int) $student->arm_id : null,
                'arm' => $student->arm_name,
                'stream' => $student->stream,
                'partial_term' => (bool) $student->partial_term,
                'results' => $results,
                'subjects_counted' => $counted,
                'subjects_missing' => $missing,
                'complete' => $missing === 0 && $counted > 0,
                'total' => $counted > 0 ? round($sum, 2) : null,
                'average' => $average !== null ? round($average, 2) : null,
                'grade' => $average !== null ? GradingScale::bandOf($bands, $average)['grade'] : null,
                'remark' => $average !== null ? GradingScale::bandOf($bands, $average)['remark'] : null,
                'passed' => $average !== null ? $average >= (float) $config['pass_mark'] : null,
                'position' => null,
                'ranked_among' => null,
            ];
        }

        $students = $this->rank($students, $config);
        $subjectStats = $this->subjectStats($students, $subjects, $config, $bands);
        $students = $this->rankSubjects($students, $subjects, $config);

        if ($withAttendance) {
            $attendance = $this->attendance($schoolId, $termId, $studentIds);

            foreach ($students as &$row) {
                $row['attendance'] = $attendance[$row['id']] ?? null;
            }
            unset($row);
        }

        return [
            'school_id' => $schoolId,
            'term' => [
                'id' => (int) $term->id,
                'name' => $term->name,
                'session_id' => (int) $term->session_id,
                'session' => $term->session?->name,
                'start_date' => (string) $term->start_date?->toDateString(),
                'end_date' => (string) $term->end_date?->toDateString(),
            ],
            'class' => [
                'id' => (int) $class->id,
                'name' => $class->name,
                'level_category' => $class->level_category,
                'level_group' => $levelGroup,
                'section' => Sections::sectionOf($levelGroup),
            ],
            'scheme' => [
                'id' => $scheme->id ? (int) $scheme->id : null,
                'name' => $scheme->name,
                'version' => (int) ($scheme->version ?? 1),
                'parts' => $scheme->parts(),
                'total_weight' => $scheme->totalWeight(),
            ],
            'grading_bands' => $bands,
            'config' => $config,
            'subjects' => array_values($subjects),
            'students' => $students,
            'subject_stats' => $subjectStats,
            'class_stats' => $this->classStats($students, $bands, $config),
            'calculated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Who sat in this class during the term.
     *
     * From enrolment records where the school keeps them for the session;
     * students placed in the class with no enrolment row for the session are
     * added, so a school part-way through adopting enrolments loses no one.
     */
    public function roster(int $schoolId, Term $term, int $classId): Collection
    {
        $enrolled = DB::table('student_enrollments')
            ->where('school_id', $schoolId)
            ->where('session_id', $term->session_id)
            ->where('class_id', $classId)
            ->where(function ($q) use ($term) {
                // Enrolled at some point during the term. Compared as a date
                // string: SQLite compares text, and a Carbon binds with a time.
                $q->whereNull('closed_on')->orWhere('closed_on', '>=', $term->start_date->toDateString());
            })
            ->get(['student_id', 'arm_id', 'enrolled_on', 'closed_on'])
            ->keyBy('student_id');

        $withAnyEnrolment = DB::table('student_enrollments')
            ->where('school_id', $schoolId)
            ->where('session_id', $term->session_id)
            ->pluck('student_id')
            ->all();

        $students = DB::table('students')
            ->leftJoin('users', 'users.id', '=', 'students.user_id')
            ->where('students.school_id', $schoolId)
            ->whereNull('students.deleted_at')
            ->where(function ($q) use ($enrolled, $classId, $withAnyEnrolment) {
                $q->whereIn('students.id', $enrolled->keys()->all() ?: [0])
                    ->orWhere(function ($w) use ($classId, $withAnyEnrolment) {
                        $w->where('students.class_id', $classId)
                            ->whereNotIn('students.id', $withAnyEnrolment ?: [0]);
                    });
            })
            ->get(['students.id', 'students.admission_number', 'students.gender', 'students.arm_id', 'users.name']);

        $arms = DB::table('arms')->where('school_id', $schoolId)->get(['id', 'name', 'stream'])->keyBy('id');

        $termStart = (string) $term->start_date?->toDateString();
        $termEnd = (string) $term->end_date?->toDateString();

        return $students->map(function ($s) use ($enrolled, $arms, $termStart, $termEnd) {
            $enrolment = $enrolled->get($s->id);

            if ($enrolment && $enrolment->arm_id) {
                $s->arm_id = $enrolment->arm_id;
            }

            $s->arm_name = $s->arm_id ? $arms->get($s->arm_id)?->name : null;
            $s->stream = $s->arm_id ? $arms->get($s->arm_id)?->stream : null;
            // Dates may be stored with a time part; compare the day only.
            $s->partial_term = $enrolment !== null && (
                ($enrolment->enrolled_on && substr((string) $enrolment->enrolled_on, 0, 10) > $termStart)
                || ($enrolment->closed_on && substr((string) $enrolment->closed_on, 0, 10) < $termEnd)
            );

            return $s;
        })->sortBy(fn ($s) => mb_strtolower((string) $s->name))->values();
    }

    /**
     * The class's subjects and, per student, the ones that apply to them.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<int, int>>}
     */
    private function subjects(int $schoolId, int $termId, int $classId, Collection $roster, Collection $entries): array
    {
        $studentIds = $roster->pluck('id')->all();
        $streamOf = $roster->pluck('stream', 'id');

        $classSubjects = (new SubjectOffering)->classRows($schoolId, $classId);

        $choices = DB::table('student_subjects')
            ->where('school_id', $schoolId)
            ->whereIn('student_id', $studentIds ?: [0])
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('term_id')->orWhere('term_id', $termId))
            ->get(['student_id', 'subject_id'])
            ->groupBy('student_id')
            ->map(fn ($rows) => $rows->pluck('subject_id')->map(fn ($id) => (int) $id)->all());

        $scored = $entries->groupBy('student_id')->map(fn ($rows) => $rows->pluck('subject_id')->map(fn ($id) => (int) $id)->all());

        $configured = $classSubjects->isNotEmpty();
        $applicable = [];
        $subjectIds = [];

        foreach ($studentIds as $studentId) {
            $mine = [];

            if ($configured) {
                foreach ($classSubjects as $subjectId => $row) {
                    // A science-only subject is not missing for an arts student.
                    $kind = SubjectOffering::kindFor($row, $streamOf->get($studentId));

                    if ($kind === 'compulsory' || ($kind !== null && in_array((int) $subjectId, $choices->get($studentId, []), true))) {
                        $mine[] = (int) $subjectId;
                    }
                }

                // A subject the student chose that the class does not list.
                $mine = array_merge($mine, $choices->get($studentId, []));
            } else {
                // No subjects configured for the class: the subjects anyone in
                // it was marked in apply to everyone, so a gap shows as missing.
                $mine = $entries->pluck('subject_id')->map(fn ($id) => (int) $id)->unique()->all();
                $mine = array_merge($mine, $choices->get($studentId, []));
            }

            $applicable[$studentId] = array_values(array_unique($mine));
            $subjectIds = array_merge($subjectIds, $applicable[$studentId], $scored->get($studentId, []));
        }

        $subjectIds = array_values(array_unique($subjectIds));

        $subjects = DB::table('subjects')
            ->where('school_id', $schoolId)
            ->whereIn('id', $subjectIds ?: [0])
            ->get(['id', 'name', 'code', 'department', 'category'])
            ->map(fn ($s) => [
                'id' => (int) $s->id,
                'name' => $s->name,
                'code' => $s->code,
                'department' => $s->department,
                'is_compulsory' => $configured
                    ? (bool) ($classSubjects->get($s->id)?->is_compulsory ?? false)
                    : ($s->category !== 'elective'),
                'streams' => $classSubjects->has($s->id) ? SubjectOffering::streamsOf($classSubjects->get($s->id)) : [],
            ])
            ->sortBy('name')
            ->values()
            ->all();

        return [$subjects, $applicable];
    }

    private function ordered(array $subjects, ?array $order): array
    {
        if (! $order) {
            return $subjects;
        }

        $rank = array_flip(array_map('intval', $order));

        usort($subjects, fn ($a, $b) => [($rank[$a['id']] ?? PHP_INT_MAX), $a['name']] <=> [($rank[$b['id']] ?? PHP_INT_MAX), $b['name']]);

        return $subjects;
    }

    /** One student's result in one subject, with its state. */
    private function resultFor(?object $entry, bool $applies, array $config, array $bands, CaScheme $scheme): array
    {
        $base = [
            'state' => $applies ? 'not_entered' : 'not_offered',
            'components' => null,
            'total' => null,
            'grade' => null,
            'remark' => null,
            'teacher_comment' => null,
            'counted' => false,
            'counted_value' => null,
            'score_entry_id' => null,
            'applies' => $applies,
            'position' => null,
        ];

        if ($entry === null) {
            return $base;
        }

        $components = is_string($entry->component_scores) ? json_decode($entry->component_scores, true) : null;

        // A mark saved before per-part marks existed carries only the three
        // legacy fields, which are exactly the classic scheme's parts.
        if (! $components) {
            $components = [
                'ca1' => (float) $entry->first_ca,
                'ca2' => (float) $entry->second_ca,
                'exam' => (float) $entry->exam,
            ];
        }

        $state = in_array($entry->result_state, ['absent', 'exempt'], true) ? $entry->result_state : 'scored';
        $total = (float) $entry->total_score;
        $band = GradingScale::bandOf($bands, $total);

        $countsAsZero = $state === 'absent' && $config['absence_policy'] === 'zero';

        return array_merge($base, [
            'state' => $state,
            'components' => $components,
            'total' => $state === 'scored' ? round($total, 2) : ($countsAsZero ? 0.0 : null),
            'grade' => $state === 'scored' ? $band['grade'] : null,
            'remark' => $state === 'scored' ? $band['remark'] : null,
            // An AI draft still awaiting its teacher is not a remark yet.
            'teacher_comment' => $entry->ai_comment_status === 'pending_approval' ? null : $entry->teacher_comment,
            'counted' => $state === 'scored' || $countsAsZero,
            'counted_value' => $state === 'scored' ? $total : ($countsAsZero ? 0.0 : null),
            'score_entry_id' => (int) $entry->id,
            'applies' => $applies,
        ]);
    }

    /**
     * Class positions by the school's rule.
     *
     * Compared on the figure as printed (two decimals), so two students whose
     * averages print the same share a place. Each ranking group (the class,
     * an arm or a stream) is ranked on its own, and `ranked_among` says how
     * many it was ranked against, so "3rd of 28" names the right 28.
     */
    private function rank(array $students, array $config): array
    {
        if ($config['ranking_basis'] === 'none') {
            return $students;
        }

        $key = $config['ranking_basis'] === 'total' ? 'total' : 'average';

        $eligible = array_filter($students, fn ($s) => $s[$key] !== null
            && ($config['ranking_scope'] !== 'complete_only' || $s['complete']));

        foreach ($this->grouped($eligible, $config) as $group) {
            $positions = $this->positions(
                array_map(fn ($s) => round((float) $s[$key], 2), $group),
                $config['ranking_method']
            );

            foreach ($positions as $index => $position) {
                $students[$index]['position'] = $position;
                $students[$index]['ranked_among'] = count($group);
            }
        }

        return $students;
    }

    private function rankSubjects(array $students, array $subjects, array $config): array
    {
        if ($config['ranking_basis'] === 'none') {
            return $students;
        }

        foreach ($subjects as $subject) {
            $scored = array_filter($students, fn ($s) => ($s['results'][$subject['id']]['state'] ?? null) === 'scored');

            foreach ($this->grouped($scored, $config) as $group) {
                $values = array_map(fn ($s) => round((float) $s['results'][$subject['id']]['total'], 2), $group);

                foreach ($this->positions($values, $config['ranking_method']) as $i => $position) {
                    $students[$i]['results'][$subject['id']]['position'] = $position;
                }
            }
        }

        return $students;
    }

    /**
     * Students split into the groups positions are taken within, keys kept.
     * A student with no arm (or an arm with no stream) is ranked with the
     * others like them rather than dropped.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function grouped(array $students, array $config): array
    {
        $groups = [];

        foreach ($students as $index => $s) {
            $key = match ($config['ranking_group'] ?? 'class') {
                'arm' => 'arm:'.($s['arm_id'] ?? 0),
                'stream' => 'stream:'.($s['stream'] ?? ''),
                default => 'class',
            };

            $groups[$key][$index] = $s;
        }

        return $groups;
    }

    /**
     * Positions for values keyed by index, highest first.
     *
     * Competition: 1, 2, 2, 4. Dense: 1, 2, 2, 3.
     *
     * @param  array<int, float>  $values
     * @return array<int, int>
     */
    public function positions(array $values, string $method = 'competition'): array
    {
        arsort($values);

        $positions = [];
        $seen = 0;
        $distinct = 0;
        $previous = null;
        $position = 0;

        foreach ($values as $index => $value) {
            $seen++;

            if ($previous === null || $value !== $previous) {
                $distinct++;
                $position = $method === 'dense' ? $distinct : $seen;
                $previous = $value;
            }

            $positions[$index] = $position;
        }

        return $positions;
    }

    private function subjectStats(array $students, array $subjects, array $config, array $bands): array
    {
        $stats = [];

        foreach ($subjects as $subject) {
            $scores = [];
            $offered = 0;
            $missing = 0;
            $distribution = [];

            foreach ($students as $s) {
                $r = $s['results'][$subject['id']];

                if ($r['applies'] || $r['state'] !== 'not_offered') {
                    $offered++;
                }

                if ($r['state'] === 'not_entered') {
                    $missing++;
                }

                if ($r['state'] === 'scored') {
                    $scores[] = (float) $r['total'];
                    $distribution[$r['grade']] = ($distribution[$r['grade']] ?? 0) + 1;
                }
            }

            $passed = count(array_filter($scores, fn ($v) => $v >= (float) $config['pass_mark']));

            $stats[$subject['id']] = [
                'subject_id' => $subject['id'],
                'subject' => $subject['name'],
                'students_offering' => $offered,
                'entered' => count($scores),
                'missing' => $missing,
                'average' => $scores ? round(array_sum($scores) / count($scores), 2) : null,
                'highest' => $scores ? max($scores) : null,
                'lowest' => $scores ? min($scores) : null,
                'pass_count' => $passed,
                'pass_rate' => $scores ? round($passed / count($scores) * 100, 1) : null,
                'grade_distribution' => $this->sortedDistribution($distribution, $bands),
            ];
        }

        return $stats;
    }

    private function classStats(array $students, array $bands, array $config): array
    {
        $averages = array_values(array_filter(array_column($students, 'average'), fn ($v) => $v !== null));
        $distribution = [];

        foreach ($students as $s) {
            if ($s['grade'] !== null) {
                $distribution[$s['grade']] = ($distribution[$s['grade']] ?? 0) + 1;
            }
        }

        $passed = count(array_filter($students, fn ($s) => $s['passed'] === true));
        $failed = count(array_filter($students, fn ($s) => $s['passed'] === false));

        return [
            'students' => count($students),
            'with_results' => count($averages),
            'complete' => count(array_filter($students, fn ($s) => $s['complete'])),
            'incomplete' => count(array_filter($students, fn ($s) => ! $s['complete'])),
            'class_average' => $averages ? round(array_sum($averages) / count($averages), 2) : null,
            'highest_average' => $averages ? max($averages) : null,
            'lowest_average' => $averages ? min($averages) : null,
            'pass_count' => $passed,
            'fail_count' => $failed,
            'pass_mark' => (int) $config['pass_mark'],
            'grade_distribution' => $this->sortedDistribution($distribution, $bands),
        ];
    }

    /** Grade counts in the scale's order, every grade present, zero or not. */
    private function sortedDistribution(array $counts, array $bands): array
    {
        $out = [];

        foreach ($bands as $band) {
            $out[] = ['grade' => $band['grade'], 'count' => (int) ($counts[$band['grade']] ?? 0)];
        }

        return $out;
    }

    /** @return array<int, array<string, mixed>> per student */
    private function attendance(int $schoolId, int $termId, array $studentIds): array
    {
        $rows = DB::table('attendance_records')
            ->where('school_id', $schoolId)
            ->where('term_id', $termId)
            ->whereIn('student_id', $studentIds ?: [0])
            ->selectRaw('student_id, status, COUNT(*) as n')
            ->groupBy('student_id', 'status')
            ->get()
            ->groupBy('student_id');

        $out = [];

        foreach ($rows as $studentId => $byStatus) {
            $counts = ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0];

            foreach ($byStatus as $row) {
                $counts[$row->status] = (int) $row->n;
            }

            $days = array_sum($counts);
            // Late still attended; excused days are school days not attended.
            $attended = $counts['present'] + $counts['late'];

            $out[(int) $studentId] = $counts + [
                'days' => $days,
                'rate' => $days > 0 ? round($attended / $days * 100, 1) : null,
            ];
        }

        return $out;
    }
}
