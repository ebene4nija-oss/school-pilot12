<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ScoreEntry;
use App\Models\Student;
use Illuminate\Support\Carbon;

/**
 * Rule-based alerts for the admin dashboard (doc §7.14).
 *
 * The spec is explicit that this is rule-based, not machine learning:
 * "Start simple; there's no evidence this market needs anything more
 * sophisticated at launch." Nothing here trains on anything — every figure is
 * a threshold a principal could check by hand, which also means every alert
 * can be explained to them when they ask why it fired.
 *
 * None of this existed; §7.14 was entirely unimplemented.
 */
class SchoolInsightsService
{
    /** A term-on-term average fall of this many points is worth flagging. */
    private const GRADE_DROP_POINTS = 10.0;

    /** Below this attendance rate a child is drifting out of school. */
    private const ATTENDANCE_RISK_PERCENTAGE = 75.0;

    /**
     * Students whose average fell materially between their two most recent
     * terms.
     *
     * @return array<int,array<string,mixed>>
     */
    public function gradeDropAlerts(int $schoolId, int $limit = 10): array
    {
        $byStudentTerm = ScoreEntry::where('school_id', $schoolId)
            ->selectRaw('student_id, term_id, AVG(total_score) as average')
            ->groupBy('student_id', 'term_id')
            ->orderBy('student_id')
            ->orderByDesc('term_id')
            ->get()
            ->groupBy('student_id');

        $alerts = [];

        foreach ($byStudentTerm as $studentId => $terms) {
            if ($terms->count() < 2) {
                continue;
            }

            $current = (float) $terms[0]->average;
            $previous = (float) $terms[1]->average;
            $delta = round($current - $previous, 2);

            if ($delta > -self::GRADE_DROP_POINTS) {
                continue;
            }

            $alerts[] = [
                'student_id' => (int) $studentId,
                'previous_average' => round($previous, 2),
                'current_average' => round($current, 2),
                'change' => $delta,
                'severity' => $delta <= -20 ? 'high' : 'medium',
            ];
        }

        usort($alerts, fn ($a, $b) => $a['change'] <=> $b['change']);
        $alerts = array_slice($alerts, 0, $limit);

        return $this->attachNames($schoolId, $alerts);
    }

    /**
     * Fee income projection from what has actually been collected so far.
     *
     * Straight-line from the term's collection rate — no seasonality model,
     * because a school that has been on the platform for one term has no
     * seasonality to learn from.
     *
     * @return array<string,mixed>
     */
    public function revenueForecast(int $schoolId): array
    {
        $invoiced = (float) Invoice::where('school_id', $schoolId)->sum('total_amount');
        $collected = (float) Payment::where('school_id', $schoolId)->where('status', 'successful')->sum('amount');

        $outstanding = round(max(0, $invoiced - $collected), 2);
        $collectionRate = $invoiced > 0 ? $collected / $invoiced : 0.0;

        // What the last 30 days actually brought in, as the run rate.
        $recent = (float) Payment::where('school_id', $schoolId)
            ->where('status', 'successful')
            ->where('paid_at', '>=', now()->subDays(30))
            ->sum('amount');

        $projected = $outstanding > 0 && $recent > 0
            ? round(min($outstanding, $recent), 2)
            : 0.0;

        return [
            'total_invoiced' => round($invoiced, 2),
            'total_collected' => round($collected, 2),
            'outstanding' => $outstanding,
            'collection_rate_percentage' => round($collectionRate * 100, 2),
            'last_30_days_collected' => round($recent, 2),
            'projected_next_30_days' => $projected,
            'months_to_clear_arrears' => $recent > 0 ? round($outstanding / $recent, 1) : null,
            'currency' => 'NGN',
        ];
    }

    /**
     * Children showing more than one withdrawal signal at once.
     *
     * Any single signal is noise — a child can miss a fortnight with malaria,
     * and plenty of families pay fees late. Two or more together is the
     * pattern that precedes a transfer, and is worth a phone call.
     *
     * @return array<int,array<string,mixed>>
     */
    public function attritionRisk(int $schoolId, int $limit = 10): array
    {
        $students = Student::where('school_id', $schoolId)
            ->where('status', '!=', 'transferred')
            ->pluck('id');

        if ($students->isEmpty()) {
            return [];
        }

        $since = now()->subDays(60)->toDateString();

        $attendance = AttendanceRecord::where('school_id', $schoolId)
            ->whereIn('student_id', $students)
            ->where('date', '>=', $since)
            ->selectRaw('student_id, COUNT(*) as total, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as present', ['present'])
            ->groupBy('student_id')
            ->get()
            ->keyBy('student_id');

        $unpaid = Invoice::where('school_id', $schoolId)
            ->whereIn('student_id', $students)
            ->whereIn('status', ['unpaid', 'partial'])
            ->selectRaw('student_id, SUM(total_amount - amount_paid) as owing')
            ->groupBy('student_id')
            ->get()
            ->keyBy('student_id');

        $drops = collect($this->gradeDropAlerts($schoolId, 500))->keyBy('student_id');

        $risks = [];

        foreach ($students as $studentId) {
            $signals = [];

            $row = $attendance->get($studentId);
            $rate = $row && $row->total > 0 ? round(($row->present / $row->total) * 100, 2) : null;

            if ($rate !== null && $rate < self::ATTENDANCE_RISK_PERCENTAGE) {
                $signals[] = "Attendance at {$rate}% over the last 60 days";
            }

            if ($owing = $unpaid->get($studentId)) {
                $amount = number_format((float) $owing->owing, 2);
                $signals[] = "Outstanding fees of ₦{$amount}";
            }

            if ($drop = $drops->get($studentId)) {
                $signals[] = "Average fell {$drop['change']} points since last term";
            }

            if (count($signals) < 2) {
                continue;
            }

            $risks[] = [
                'student_id' => (int) $studentId,
                'signals' => $signals,
                'signal_count' => count($signals),
                'severity' => count($signals) >= 3 ? 'high' : 'medium',
                'attendance_percentage' => $rate,
            ];
        }

        usort($risks, fn ($a, $b) => $b['signal_count'] <=> $a['signal_count']);

        return $this->attachNames($schoolId, array_slice($risks, 0, $limit));
    }

    /**
     * One resolve of names for a whole alert list, rather than a query per row.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function attachNames(int $schoolId, array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $names = Student::where('school_id', $schoolId)
            ->whereIn('id', array_column($rows, 'student_id'))
            ->with('user:id,name')
            ->get()
            ->keyBy('id');

        foreach ($rows as $index => $row) {
            $student = $names->get($row['student_id']);
            $rows[$index]['student_name'] = $student?->user?->name;
            $rows[$index]['admission_number'] = $student?->admission_number;
        }

        return $rows;
    }
}
