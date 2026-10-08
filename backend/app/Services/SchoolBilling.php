<?php

namespace App\Services;

use App\Models\HostelEnrollment;
use App\Models\School;
use App\Models\StudentEnrollment;
use App\Models\Term;
use App\Models\TermBill;
use Illuminate\Support\Carbon;

/**
 * What a school owes SchoolPilot for a term (doc §13, priced 2026-10-08).
 *
 * Two plans, both per active student per term:
 *  - `results` (Result Management, ₦500): the whole platform plus result
 *    computation, printing and PINs. No trial.
 *  - `subscription` (School Management Subscription, ₦300): the platform
 *    without SchoolPilot's result services, after a free trial.
 * Boarders are charged the boarding rate (₦1,000) instead, on either plan.
 * Rates live in services.billing and are editable in the console.
 *
 * "Active" means enrolled in a class at any point during the term — a
 * student withdrawn in week 3 still counted for that term. Withdrawn and
 * graduated students from earlier terms do not count. A boarder is a student
 * with a full, weekly or flexible hostel enrolment overlapping the term
 * (day services are not boarding).
 *
 * The free trial covers a term only if it runs to the term's end: once the
 * trial ends during a term, that term is billable in full.
 */
class SchoolBilling
{
    public const PLANS = [
        'results' => 'School Management with Result Management',
        'subscription' => 'School Management Subscription',
    ];

    private const BOARDING = ['full', 'weekly', 'flexible'];

    private const BOARDING_STATUSES = ['active', 'suspended', 'completed'];

    public static function rateKobo(string $plan): int
    {
        return (int) config($plan === 'results' ? 'services.billing.results_rate_kobo' : 'services.billing.subscription_rate_kobo');
    }

    public static function boardingRateKobo(): int
    {
        return (int) config('services.billing.boarding_rate_kobo');
    }

    /** Distinct students enrolled in a class at any point during the term. */
    public function activeStudents(Term $term): int
    {
        [$start, $end] = $this->span($term);

        return StudentEnrollment::withoutGlobalScopes()
            ->where('school_id', $term->school_id)
            ->where('session_id', $term->session_id)
            ->where(fn ($q) => $q->whereNull('enrolled_on')->orWhere('enrolled_on', '<=', $end))
            ->where(fn ($q) => $q->whereNull('closed_on')->orWhere('closed_on', '>=', $start))
            ->distinct()
            ->count('student_id');
    }

    /** Of those, how many boarded at any point during the term. */
    public function boardingStudents(Term $term): int
    {
        [$start, $end] = $this->span($term);

        $enrolled = StudentEnrollment::withoutGlobalScopes()
            ->where('school_id', $term->school_id)
            ->where('session_id', $term->session_id)
            ->where(fn ($q) => $q->whereNull('enrolled_on')->orWhere('enrolled_on', '<=', $end))
            ->where(fn ($q) => $q->whereNull('closed_on')->orWhere('closed_on', '>=', $start))
            ->select('student_id');

        return HostelEnrollment::withoutGlobalScopes()
            ->where('school_id', $term->school_id)
            ->whereIn('boarding_category', self::BOARDING)
            ->whereIn('status', self::BOARDING_STATUSES)
            ->whereIn('student_id', $enrolled)
            ->where(fn ($q) => $q->whereNull('starts_on')->orWhere('starts_on', '<=', $end))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $start))
            ->distinct()
            ->count('student_id');
    }

    /** Whether the subscription trial still covers this whole term. */
    public function trialCovers(School $school, Term $term): bool
    {
        return $school->billing_plan === 'subscription'
            && $school->trial_ends_on !== null
            && Carbon::parse($school->trial_ends_on)->gte($this->span($term)[1]);
    }

    /**
     * The term's figures: the issued bill if there is one (frozen), otherwise
     * what would be billed today.
     *
     * @return array<string, mixed>
     */
    public function statement(School $school, Term $term): array
    {
        $bill = TermBill::where('school_id', $school->id)->where('term_id', $term->id)->first();

        if ($bill) {
            return $this->describe($bill->plan, $bill->active_students, $bill->boarding_students, $bill->rate_kobo, $bill->boarding_rate_kobo) + [
                'bill' => [
                    'id' => $bill->id,
                    'status' => $bill->status,
                    'issued_at' => $bill->created_at?->toIso8601String(),
                    'paid_at' => $bill->paid_at?->toIso8601String(),
                    'note' => $bill->note,
                ],
                'in_trial' => false,
            ];
        }

        $plan = $school->billing_plan;
        $students = $this->activeStudents($term);
        $boarders = $this->boardingStudents($term);

        return $this->describe($plan, $students, $boarders, $plan ? self::rateKobo($plan) : 0, self::boardingRateKobo()) + [
            'bill' => null,
            'in_trial' => $this->trialCovers($school, $term),
        ];
    }

    /**
     * Freeze this term's figures into a bill.
     *
     * @throws \DomainException when there is nothing to bill
     */
    public function issue(School $school, Term $term, ?int $userId): TermBill
    {
        if (! $school->billing_plan) {
            throw new \DomainException('Choose a plan for this school first.');
        }

        if ($this->trialCovers($school, $term)) {
            throw new \DomainException('This term is inside the school\'s free trial.');
        }

        if (TermBill::where('school_id', $school->id)->where('term_id', $term->id)->exists()) {
            throw new \DomainException('This term has already been billed.');
        }

        $s = $this->statement($school, $term);

        return TermBill::create([
            'school_id' => $school->id,
            'term_id' => $term->id,
            'plan' => $school->billing_plan,
            'active_students' => $s['active_students'],
            'rate_kobo' => $s['rate_kobo'],
            'boarding_students' => $s['boarding_students'],
            'boarding_rate_kobo' => $s['boarding_rate_kobo'],
            'amount_kobo' => $s['amount_kobo'],
            'status' => 'issued',
            'issued_by' => $userId,
        ]);
    }

    /** @return array<string, mixed> */
    private function describe(?string $plan, int $students, int $boarders, int $rate, int $boardingRate): array
    {
        $boarders = min($boarders, $students);
        $day = $students - $boarders;

        return [
            'plan' => $plan,
            'active_students' => $students,
            'day_students' => $day,
            'boarding_students' => $boarders,
            'rate_kobo' => $rate,
            'boarding_rate_kobo' => $boardingRate,
            'amount_kobo' => $plan ? $day * $rate + $boarders * $boardingRate : 0,
        ];
    }

    /** @return array{0: string, 1: string} */
    private function span(Term $term): array
    {
        return [
            Carbon::parse($term->start_date)->toDateString(),
            Carbon::parse($term->end_date)->toDateString(),
        ];
    }
}
