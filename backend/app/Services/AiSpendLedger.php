<?php

namespace App\Services;

use App\Exceptions\AiBudgetExceededException;
use App\Models\AiUsageDaily;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Per-school daily AI spend: metering, the cap, and the 80% alert.
 *
 * LAUNCH.md §1 promised "daily spend cap per school with an automated alert at
 * 80% budget utilization". None of it existed — no counter, no cap, no alert —
 * so AI spend was unbounded per tenant. A tutor-chat loop, a bulk report-card
 * run against 900 students, or simply a school that likes the feature could
 * bill the platform without limit.
 *
 * Two calls make up the contract, and callers need both:
 *
 *   assertWithinCap()  before the request — refuses when the budget is spent
 *   record()           after the response  — meters what it actually cost
 *
 * The cap is checked *before* each call rather than enforced mid-flight, so the
 * request that crosses the line still completes. A school can therefore finish
 * fractionally over its cap; that is the correct trade against truncating a
 * half-generated report-card comment.
 */
class AiSpendLedger
{
    /**
     * Anthropic's published USD price per million tokens, as of 2026-08-11.
     *
     * Sonnet 5 is on introductory pricing ($2/$10) through 2026-08-31, after
     * which it returns to $3/$15 — hence `input_after_intro`, so the reversion
     * does not silently under-bill. An unknown model falls back to the most
     * expensive row rather than to zero: under-metering an unrecognised model
     * would let it run past the cap unnoticed.
     */
    private const DEFAULT_PRICING = [
        'claude-haiku-4-5' => ['input' => 1.00, 'output' => 5.00],
        'claude-sonnet-5' => [
            'input' => 2.00,
            'output' => 10.00,
            'input_after_intro' => 3.00,
            'output_after_intro' => 15.00,
            'intro_ends' => '2026-08-31',
        ],
        'claude-opus-5' => ['input' => 5.00, 'output' => 25.00],
    ];

    /** Cache reads bill at a tenth of the input rate; writes at 1.25x. */
    private const CACHE_READ_MULTIPLIER = 0.1;
    private const CACHE_WRITE_MULTIPLIER = 1.25;

    /**
     * Refuse the call when the school has already spent its budget.
     *
     * @throws AiBudgetExceededException
     */
    public function assertWithinCap(?int $schoolId): void
    {
        $cap = $this->capKobo();

        // No cap configured means no ceiling — the documented default, so a
        // deployment that has not set a budget is not silently blocked.
        if ($cap === null || $schoolId === null) {
            return;
        }

        $spent = $this->spentTodayKobo($schoolId);

        if ($spent >= $cap) {
            Log::warning('AI budget cap reached; refusing the request.', [
                'school_id' => $schoolId,
                'spent_kobo' => $spent,
                'cap_kobo' => $cap,
            ]);

            throw new AiBudgetExceededException($schoolId, $spent, $cap);
        }
    }

    /**
     * Meter one completed call.
     *
     * `$usage` is the Messages API `usage` object as returned — input_tokens,
     * output_tokens, and the two cache counters. Missing keys count as zero so
     * a provider-side shape change under-reports rather than throwing in the
     * middle of a successful generation.
     *
     * @param array<string,mixed> $usage
     */
    public function record(?int $schoolId, string $model, array $usage): void
    {
        if ($schoolId === null) {
            return;
        }

        $input = (int) ($usage['input_tokens'] ?? 0);
        $output = (int) ($usage['output_tokens'] ?? 0);
        $cacheRead = (int) ($usage['cache_read_input_tokens'] ?? 0);
        $cacheWrite = (int) ($usage['cache_creation_input_tokens'] ?? 0);

        $costKobo = $this->costKobo($model, $input, $output, $cacheRead, $cacheWrite);
        $row = $this->todayRow($schoolId);

        /*
         * Incremented in SQL, by primary key, rather than read-modify-written
         * in PHP. Two report-card jobs finishing at once would otherwise each
         * read the same total and write it back, losing one call's spend —
         * exactly the drift that makes a cap unenforceable at the concurrency
         * this runs at during term end.
         */
        AiUsageDaily::whereKey($row->getKey())->update([
            'input_tokens' => DB::raw('input_tokens + ' . $input),
            'output_tokens' => DB::raw('output_tokens + ' . $output),
            'cache_read_tokens' => DB::raw('cache_read_tokens + ' . $cacheRead),
            'cache_write_tokens' => DB::raw('cache_write_tokens + ' . $cacheWrite),
            'cost_kobo' => DB::raw('cost_kobo + ' . $costKobo),
            'calls' => DB::raw('calls + 1'),
            'updated_at' => now(),
        ]);

        $this->alertIfNearCap($schoolId);
    }

    /** What this school has spent today, in kobo. */
    public function spentTodayKobo(int $schoolId): int
    {
        return (int) $this->todayQuery($schoolId)->value('cost_kobo');
    }

    /**
     * Today's row for a school, created if this is the day's first call.
     *
     * `whereDate` rather than an equality match on the raw value: the model
     * casts `usage_date` to a date, which Eloquent writes through the
     * connection's datetime format, so the stored value is not byte-equal to
     * a `Y-m-d` string. Matching on equality silently found nothing, and every
     * increment updated zero rows.
     */
    private function todayRow(int $schoolId): AiUsageDaily
    {
        $row = $this->todayQuery($schoolId)->first();

        if ($row) {
            return $row;
        }

        try {
            return AiUsageDaily::create([
                'school_id' => $schoolId,
                'usage_date' => now()->toDateString(),
                'usd_to_ngn' => $this->usdToNgn(),
            ]);
        } catch (QueryException $e) {
            // Two calls raced to open the day's row; the unique index rejected
            // the loser. Its row exists now, so take that one.
            $row = $this->todayQuery($schoolId)->first();

            if (! $row) {
                throw $e;
            }

            return $row;
        }
    }

    private function todayQuery(int $schoolId)
    {
        return AiUsageDaily::where('school_id', $schoolId)
            ->whereDate('usage_date', now()->toDateString());
    }

    /**
     * Today's spend as a proportion of the cap, or null when uncapped.
     * Exposed for the admin dashboard, which shows a school its own burn rate.
     */
    public function utilisation(int $schoolId): ?float
    {
        $cap = $this->capKobo();

        return $cap === null || $cap === 0
            ? null
            : $this->spentTodayKobo($schoolId) / $cap;
    }

    /**
     * Fire the 80% alert once per school per day.
     *
     * `alerted_at` makes it once-per-day rather than once-per-call: a bulk
     * report-card run crosses the threshold on one call and then makes several
     * hundred more, and an alert per call is an alert nobody reads.
     */
    private function alertIfNearCap(int $schoolId): void
    {
        $cap = $this->capKobo();

        if ($cap === null || $cap === 0) {
            return;
        }

        $row = $this->todayQuery($schoolId)->first();

        if (! $row || $row->alerted_at !== null) {
            return;
        }

        $threshold = (float) config('services.anthropic.alert_threshold', 0.8);

        if ($row->cost_kobo / $cap < $threshold) {
            return;
        }

        Log::warning('AI spend has passed the alert threshold for this school.', [
            'school_id' => $schoolId,
            'spent_kobo' => $row->cost_kobo,
            'cap_kobo' => $cap,
            'utilisation' => round($row->cost_kobo / $cap, 4),
        ]);

        $row->forceFill(['alerted_at' => now()])->save();
    }

    /** Cost of one call in kobo, rounded up so a cap is never overshot by rounding. */
    private function costKobo(string $model, int $input, int $output, int $cacheRead, int $cacheWrite): int
    {
        $pricing = $this->pricingFor($model);

        $usd = ($input / 1_000_000) * $pricing['input']
            + ($output / 1_000_000) * $pricing['output']
            + ($cacheRead / 1_000_000) * $pricing['input'] * self::CACHE_READ_MULTIPLIER
            + ($cacheWrite / 1_000_000) * $pricing['input'] * self::CACHE_WRITE_MULTIPLIER;

        return (int) ceil($usd * $this->usdToNgn() * 100);
    }

    /**
     * @return array{input: float, output: float}
     */
    private function pricingFor(string $model): array
    {
        $table = config('services.anthropic.pricing') ?: self::DEFAULT_PRICING;

        if (! isset($table[$model])) {
            // Unknown model: bill at the most expensive row we know about. An
            // unrecognised model is far more likely to be a newer, pricier one
            // than a cheaper one, and metering it at zero would uncap it.
            Log::warning('No AI price for model; metering at the highest known rate.', ['model' => $model]);

            return $this->mostExpensive($table);
        }

        $row = $table[$model];

        // Introductory pricing that has lapsed reverts to the standard rate.
        if (isset($row['intro_ends']) && now()->gt($row['intro_ends'])) {
            return [
                'input' => (float) ($row['input_after_intro'] ?? $row['input']),
                'output' => (float) ($row['output_after_intro'] ?? $row['output']),
            ];
        }

        return ['input' => (float) $row['input'], 'output' => (float) $row['output']];
    }

    /**
     * @param array<string,array<string,mixed>> $table
     * @return array{input: float, output: float}
     */
    private function mostExpensive(array $table): array
    {
        $best = ['input' => 5.00, 'output' => 25.00];

        foreach ($table as $row) {
            $output = (float) ($row['output_after_intro'] ?? $row['output'] ?? 0);

            if ($output > $best['output']) {
                $best = [
                    'input' => (float) ($row['input_after_intro'] ?? $row['input'] ?? 0),
                    'output' => $output,
                ];
            }
        }

        return $best;
    }

    /** Null means uncapped. */
    private function capKobo(): ?int
    {
        $cap = config('services.anthropic.daily_cap_kobo');

        return is_numeric($cap) && (int) $cap > 0 ? (int) $cap : null;
    }

    private function usdToNgn(): float
    {
        return (float) config('services.anthropic.usd_to_ngn', 1500);
    }
}
