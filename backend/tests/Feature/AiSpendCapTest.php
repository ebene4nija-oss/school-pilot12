<?php

namespace Tests\Feature;

use App\Exceptions\AiBudgetExceededException;
use App\Models\AiUsageDaily;
use App\Models\School;
use App\Services\AiSpendLedger;
use App\Services\ClaudeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Per-school daily AI spend cap (LAUNCH.md §1).
 *
 * The pre-flight item promised "$25/day per school with an alert at 80%".
 * Nothing tracked AI spend at all — no counter, no cap, no alert — so the
 * commitment was unmeetable and per-tenant AI cost was unbounded.
 */
class AiSpendCapTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create([
            'name' => 'Hilltop Sec',
            'slug' => 'hilltop',
            'subdomain' => 'hilltop',
        ]);

        config()->set('services.anthropic.usd_to_ngn', 1500);
        config()->set('services.anthropic.daily_cap_kobo', 2_500_000); // ₦25,000
    }

    private function ledger(): AiSpendLedger
    {
        return app(AiSpendLedger::class);
    }

    /**
     * Pricing is checked against Anthropic's published rates rather than
     * assumed, so a silent price change shows up here rather than on the bill.
     *
     * Haiku 4.5: $1/MTok input, $5/MTok output. At ₦1,500/USD, one million
     * input tokens is $1.00 = ₦1,500 = 150,000 kobo.
     */
    public function test_cost_is_computed_from_published_per_token_pricing()
    {
        $this->ledger()->record($this->school->id, 'claude-haiku-4-5', [
            'input_tokens' => 1_000_000,
            'output_tokens' => 0,
        ]);

        $this->assertSame(150_000, $this->ledger()->spentTodayKobo($this->school->id));

        // Output is 5x input: 1M output tokens = $5.00 = ₦7,500 = 750,000 kobo.
        $this->ledger()->record($this->school->id, 'claude-haiku-4-5', [
            'input_tokens' => 0,
            'output_tokens' => 1_000_000,
        ]);

        $this->assertSame(900_000, $this->ledger()->spentTodayKobo($this->school->id));
    }

    /** Cache reads bill at a tenth of input; a cap that ignored them would drift. */
    public function test_cached_tokens_are_metered_at_their_own_rate()
    {
        $this->ledger()->record($this->school->id, 'claude-haiku-4-5', [
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cache_read_input_tokens' => 1_000_000,
        ]);

        // $1.00 x 0.1 = $0.10 = ₦150 = 15,000 kobo.
        $this->assertSame(15_000, $this->ledger()->spentTodayKobo($this->school->id));
    }

    public function test_spend_accumulates_across_calls_and_counts_them()
    {
        foreach (range(1, 3) as $_) {
            $this->ledger()->record($this->school->id, 'claude-haiku-4-5', [
                'input_tokens' => 10_000,
                'output_tokens' => 1_000,
            ]);
        }

        $row = AiUsageDaily::where('school_id', $this->school->id)->first();

        $this->assertSame(3, $row->calls);
        $this->assertSame(30_000, $row->input_tokens);
        $this->assertSame(3_000, $row->output_tokens);
    }

    public function test_a_school_under_its_cap_is_allowed_through()
    {
        $this->ledger()->record($this->school->id, 'claude-haiku-4-5', [
            'input_tokens' => 1_000_000, // 150,000 kobo of a 2,500,000 cap
        ]);

        $this->ledger()->assertWithinCap($this->school->id);

        $this->assertTrue(true, 'No exception: the school is still within budget.');
    }

    public function test_a_school_at_its_cap_is_refused()
    {
        $this->ledger()->record($this->school->id, 'claude-haiku-4-5', [
            'output_tokens' => 5_000_000, // $25 = ₦37,500, past the ₦25,000 cap
        ]);

        $this->expectException(AiBudgetExceededException::class);
        $this->ledger()->assertWithinCap($this->school->id);
    }

    /** One school burning its budget must not affect another. */
    public function test_the_cap_is_per_school()
    {
        $other = School::create(['name' => 'Other', 'slug' => 'other', 'subdomain' => 'other']);

        $this->ledger()->record($this->school->id, 'claude-haiku-4-5', ['output_tokens' => 5_000_000]);

        // The spendthrift school is blocked...
        try {
            $this->ledger()->assertWithinCap($this->school->id);
            $this->fail('Expected the over-budget school to be refused.');
        } catch (AiBudgetExceededException $e) {
            $this->assertSame($this->school->id, $e->schoolId);
        }

        // ...and its neighbour is unaffected.
        $this->ledger()->assertWithinCap($other->id);
        $this->assertSame(0, $this->ledger()->spentTodayKobo($other->id));
    }

    /**
     * The default must stay uncapped. Shipping a cap switched on would throttle
     * every existing deployment the moment this migration ran.
     */
    public function test_no_cap_configured_means_no_ceiling()
    {
        config()->set('services.anthropic.daily_cap_kobo', null);

        $this->ledger()->record($this->school->id, 'claude-opus-5', ['output_tokens' => 100_000_000]);

        $this->ledger()->assertWithinCap($this->school->id);
        $this->assertNull($this->ledger()->utilisation($this->school->id));
    }

    public function test_the_alert_fires_once_when_spend_passes_eighty_percent()
    {
        // 80% of ₦25,000 is ₦20,000 = 2,000,000 kobo. At $5/MTok output and
        // ₦1,500/USD, 2.7M output tokens is ₦20,250 — just over the line.
        $this->ledger()->record($this->school->id, 'claude-haiku-4-5', ['output_tokens' => 2_700_000]);

        $row = AiUsageDaily::where('school_id', $this->school->id)->first();
        $this->assertNotNull($row->alerted_at, 'Crossing 80% should stamp the alert.');

        $firedAt = $row->alerted_at;

        // A bulk report-card run makes hundreds more calls after crossing the
        // threshold; the alert must not re-fire for each one.
        $this->ledger()->record($this->school->id, 'claude-haiku-4-5', ['output_tokens' => 1_000]);

        $this->assertEquals($firedAt, $row->fresh()->alerted_at);
    }

    public function test_the_alert_stays_quiet_below_the_threshold()
    {
        $this->ledger()->record($this->school->id, 'claude-haiku-4-5', ['output_tokens' => 1_000_000]);

        $this->assertNull(AiUsageDaily::where('school_id', $this->school->id)->first()->alerted_at);
    }

    /**
     * Sonnet 5 runs on introductory pricing ($2/$10) until 2026-08-31 and
     * reverts to $3/$15 after. Metering must follow, or every call from
     * September onward is under-billed by a third.
     */
    public function test_introductory_pricing_reverts_on_schedule()
    {
        $this->travelTo('2026-08-15');
        $this->ledger()->record($this->school->id, 'claude-sonnet-5', ['input_tokens' => 1_000_000]);
        // $2.00 = ₦3,000 = 300,000 kobo
        $this->assertSame(300_000, $this->ledger()->spentTodayKobo($this->school->id));

        $this->travelTo('2026-09-15');
        $this->ledger()->record($this->school->id, 'claude-sonnet-5', ['input_tokens' => 1_000_000]);
        // $3.00 = ₦4,500 = 450,000 kobo, on a fresh day's row
        $this->assertSame(450_000, $this->ledger()->spentTodayKobo($this->school->id));

        $this->travelBack();
    }

    /** An unrecognised model must not meter as free — that would uncap it. */
    public function test_an_unknown_model_is_metered_at_the_highest_known_rate()
    {
        $this->ledger()->record($this->school->id, 'claude-something-unreleased', [
            'output_tokens' => 1_000_000,
        ]);

        // Falls back to the priciest known row ($25/MTok output) = ₦37,500.
        $this->assertSame(3_750_000, $this->ledger()->spentTodayKobo($this->school->id));
    }

    /** Yesterday's spend must not count against today's budget. */
    public function test_the_budget_resets_daily()
    {
        $this->travelTo('2026-08-11 09:00');
        $this->ledger()->record($this->school->id, 'claude-haiku-4-5', ['output_tokens' => 5_000_000]);
        $this->assertSame(3_750_000, $this->ledger()->spentTodayKobo($this->school->id));

        $this->travelTo('2026-08-12 09:00');
        $this->assertSame(0, $this->ledger()->spentTodayKobo($this->school->id));
        $this->ledger()->assertWithinCap($this->school->id);

        $this->travelBack();
    }

    // -----------------------------------------------------------------
    // Integration: the cap actually gates a real generation path
    // -----------------------------------------------------------------

    public function test_the_cap_blocks_a_report_card_comment_before_the_api_is_called()
    {
        Cache::flush();
        Http::fake();
        config()->set('services.anthropic.api_key', 'live-key');
        config()->set('services.anthropic.stub', false);

        $this->ledger()->record($this->school->id, 'claude-haiku-4-5', ['output_tokens' => 5_000_000]);

        $this->expectException(AiBudgetExceededException::class);

        try {
            app(ClaudeService::class)->generateReportCardComment([
                'school_id' => $this->school->id,
                'student_name' => 'Chinelo',
                'subject' => 'Mathematics',
                'total_score' => 78,
            ]);
        } finally {
            // The point of a pre-call gate: no spend is incurred proving we
            // have no budget left.
            Http::assertNothingSent();
        }
    }

    public function test_a_successful_generation_meters_the_usage_the_api_reported()
    {
        Cache::flush();
        config()->set('services.anthropic.api_key', 'live-key');
        config()->set('services.anthropic.stub', false);

        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'Chinelo has worked steadily this term.']],
            'usage' => ['input_tokens' => 120, 'output_tokens' => 45],
        ], 200)]);

        app(ClaudeService::class)->generateReportCardComment([
            'school_id' => $this->school->id,
            'student_name' => 'Chinelo',
            'subject' => 'Mathematics',
            'total_score' => 78,
        ]);

        $row = AiUsageDaily::where('school_id', $this->school->id)->first();

        $this->assertNotNull($row, 'A successful call must be metered.');
        $this->assertSame(120, $row->input_tokens);
        $this->assertSame(45, $row->output_tokens);
        $this->assertSame(1, $row->calls);
    }
}
