<?php

namespace App\Services;

use App\Models\ResultPinBatch;
use App\Models\ResultPinSale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Settles a verified gateway payment against a result-checker purchase.
 *
 * Called only from FinanceController::handleWebhook, after that method has
 * checked the gateway's signature. Nothing here re-verifies the caller, so it
 * must never be reachable from an unsigned path — it mints sellable inventory.
 *
 * Kept out of the controller because both branches need transactional locking
 * and amount checks, and burying that inside an already long webhook handler is
 * how the two get out of step.
 */
class ResultPinCheckoutService
{
    public function __construct(private ResultPinService $pins)
    {
    }

    /**
     * Resolve a reference to a pending purchase and complete it.
     *
     * Cross-tenant by necessity: a gateway callback carries a reference and
     * nothing else, so the school is what we are trying to discover.
     */
    public function settle(string $reference, float $amountPaid): void
    {
        if ($this->settleBatch($reference, $amountPaid)) {
            return;
        }

        $this->settleSale($reference, $amountPaid);
    }

    /** A school buying PIN stock from SchoolPilot. */
    private function settleBatch(string $reference, float $amountPaid): bool
    {
        return DB::transaction(function () use ($reference, $amountPaid) {
            $batch = ResultPinBatch::withoutGlobalScopes()
                ->where('reference', $reference)
                ->lockForUpdate()
                ->first();

            if (! $batch) {
                return false;
            }

            // Already settled — a retried delivery. Claim the reference so the
            // sale branch does not also try to handle it, but mint nothing.
            if ($batch->status === 'active') {
                return true;
            }

            if ($batch->status === 'cancelled') {
                Log::warning('Result PIN batch payment arrived for a cancelled batch.', [
                    'reference' => $reference,
                ]);

                return true;
            }

            /*
             * Underpayment does not buy PINs.
             *
             * The amount is taken from the gateway payload, not from what the
             * client claimed at checkout, so a tampered init cannot buy 1,000
             * PINs for ₦100. A half-naira tolerance absorbs gateway rounding
             * without opening a meaningful gap.
             */
            if ($amountPaid + 0.5 < (float) $batch->total_amount) {
                Log::warning('Result PIN batch underpaid; PINs withheld.', [
                    'reference' => $reference,
                    'expected' => $batch->total_amount,
                    'received' => $amountPaid,
                ]);

                return true;
            }

            $batch->update([
                'status' => 'active',
                'paid_at' => now(),
            ]);

            $this->pins->mintPins($batch, $batch->quantity);

            return true;
        });
    }

    /** A guardian buying one PIN from a school. */
    private function settleSale(string $reference, float $amountPaid): bool
    {
        return DB::transaction(function () use ($reference, $amountPaid) {
            $sale = ResultPinSale::withoutGlobalScopes()
                ->where('reference', $reference)
                ->lockForUpdate()
                ->first();

            if (! $sale) {
                return false;
            }

            if ($sale->status === 'successful') {
                return true;
            }

            if ($amountPaid + 0.5 < (float) $sale->amount) {
                Log::warning('Result PIN sale underpaid; result withheld.', [
                    'reference' => $reference,
                    'expected' => $sale->amount,
                    'received' => $amountPaid,
                ]);

                return true;
            }

            /*
             * If the guardian already has access — they redeemed a counter PIN
             * while the transfer was in flight, or the school waived the fee —
             * the payment is still recorded, but no second PIN is consumed.
             * The refund is a conversation with the school, not something to
             * paper over by silently burning stock.
             */
            $existing = $this->pins->accessFor($sale->school_id, $sale->student_id, $sale->term_id);

            if ($existing) {
                $sale->update([
                    'status' => 'successful',
                    'result_pin_id' => $existing->id,
                    'paid_at' => now(),
                ]);

                return true;
            }

            $pin = $this->pins->allocateAndBind(
                (int) $sale->school_id,
                (int) $sale->student_id,
                (int) $sale->term_id,
                (int) $sale->purchased_by,
                'online',
                (string) $sale->amount
            );

            $sale->update([
                'status' => 'successful',
                'result_pin_id' => $pin->id,
                'paid_at' => now(),
            ]);

            return true;
        });
    }
}
