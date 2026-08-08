<?php

namespace App\Services;

use App\Models\ResultPin;
use App\Models\ResultPinBatch;
use App\Models\School;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Minting, allocating and spending result-checker PINs.
 *
 * All the stock arithmetic lives here rather than in the controller because
 * three different entry points move the same inventory — a webhook settling an
 * online purchase, a bursar selling over the counter, and a guardian redeeming
 * a printed code — and they must not each grow their own version of "take one
 * PIN off the shelf".
 */
class ResultPinService
{
    /**
     * Mint `quantity` PINs against a batch and return the plaintext codes.
     *
     * The codes are returned exactly once, here. After this they exist only as
     * an encrypted column, readable through the audited reveal endpoint.
     */
    public function mintPins(ResultPinBatch $batch, int $quantity): array
    {
        $codes = [];

        DB::transaction(function () use ($batch, $quantity, &$codes) {
            for ($i = 0; $i < $quantity; $i++) {
                $code = ResultPin::generateCode();

                $pin = new ResultPin([
                    'school_id' => $batch->school_id,
                    'batch_id' => $batch->id,
                    'serial' => $this->uniqueSerial(),
                    'origin' => 'batch',
                    'status' => 'available',
                    'max_views' => $this->maxViews(),
                ]);
                $pin->setCode($code);
                $pin->save();

                $codes[] = ['serial' => $pin->serial, 'pin' => $code];
            }
        });

        return $codes;
    }

    /**
     * Take one PIN off the shelf and bind it to a child's term result.
     *
     * `lockForUpdate` inside a transaction is what stops two simultaneous
     * webhook deliveries — gateways retry, and Paystack will happily deliver
     * the same event twice — from handing the same physical PIN to two sales.
     *
     * When stock is exhausted an overdraft PIN is minted instead of failing.
     * The guardian has already paid the school at this point; refusing them a
     * result because the school forgot to restock is the school's problem to
     * settle with SchoolPilot, not the parent's to absorb at the till.
     */
    public function allocateAndBind(
        int $schoolId,
        int $studentId,
        int $termId,
        ?int $soldToUserId,
        string $channel,
        ?string $amount
    ): ResultPin {
        return DB::transaction(function () use ($schoolId, $studentId, $termId, $soldToUserId, $channel, $amount) {
            $pin = ResultPin::withoutGlobalScopes()
                ->where('school_id', $schoolId)
                ->where('status', 'available')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (! $pin) {
                $pin = new ResultPin([
                    'school_id' => $schoolId,
                    'batch_id' => null,
                    'serial' => $this->uniqueSerial(),
                    'origin' => 'overdraft',
                    'max_views' => $this->maxViews(),
                ]);
                $pin->setCode(ResultPin::generateCode());
            }

            $pin->fill([
                'status' => 'used',
                'student_id' => $studentId,
                'term_id' => $termId,
                'sold_to' => $soldToUserId,
                'sold_channel' => $channel,
                'sold_amount' => $amount,
                'sold_at' => now(),
                'first_used_at' => now(),
                'last_used_at' => now(),
            ]);
            $pin->save();

            return $pin;
        });
    }

    /**
     * Redeem a code a guardian bought over the counter.
     *
     * Binds an unbound PIN to this child and term. A PIN already bound to this
     * same pairing is returned as-is so that re-entering a code you already
     * used is a no-op rather than an error or a second charge.
     *
     * @throws RuntimeException with a message safe to show the guardian.
     */
    public function redeem(int $schoolId, string $code, int $studentId, int $termId): ResultPin
    {
        $hash = ResultPin::hashCode($code);

        return DB::transaction(function () use ($schoolId, $hash, $studentId, $termId) {
            $pin = ResultPin::withoutGlobalScopes()
                ->where('school_id', $schoolId)
                ->where('pin_hash', $hash)
                ->lockForUpdate()
                ->first();

            /*
             * Same message for "no such PIN" and "PIN belongs to another
             * school". Distinguishing them turns the endpoint into an oracle
             * for testing whether a code is live somewhere on the platform.
             */
            if (! $pin) {
                throw new RuntimeException('That PIN is not valid. Check the code and try again.');
            }

            if ($pin->status === 'void') {
                throw new RuntimeException('That PIN has been cancelled. Please contact the school.');
            }

            if ($pin->status === 'used') {
                if ((int) $pin->student_id === $studentId && (int) $pin->term_id === $termId) {
                    return $pin;
                }

                throw new RuntimeException('That PIN has already been used for another student or term.');
            }

            $pin->fill([
                'status' => 'used',
                'student_id' => $studentId,
                'term_id' => $termId,
                'first_used_at' => now(),
                'last_used_at' => now(),
            ]);
            $pin->save();

            return $pin;
        });
    }

    /**
     * The live PIN unlocking this result, if any.
     *
     * Deliberately not scoped to the guardian who paid: a household where the
     * mother bought the PIN and the father opens the app is one result, not
     * two. The guardian relationship to the child is what authorizes the read,
     * and that is checked by the policy before this is ever called.
     */
    public function accessFor(int $schoolId, int $studentId, int $termId): ?ResultPin
    {
        return ResultPin::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('student_id', $studentId)
            ->where('term_id', $termId)
            ->where('status', 'used')
            ->whereColumn('views_used', '<', 'max_views')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Burn one view.
     *
     * An atomic increment rather than read-modify-write: two tabs opening the
     * report card at once would otherwise both read views_used = 4 and both
     * write 5, giving away a free view each time.
     */
    public function consumeView(ResultPin $pin): void
    {
        ResultPin::withoutGlobalScopes()
            ->where('id', $pin->id)
            ->update([
                'views_used' => DB::raw('views_used + 1'),
                'last_used_at' => now(),
            ]);
    }

    /** Grant a child free access — scholarship, hardship, or a billing dispute. */
    public function waive(int $schoolId, int $studentId, int $termId, int $grantedByUserId, ?string $reason): ResultPin
    {
        $pin = new ResultPin([
            'school_id' => $schoolId,
            'batch_id' => null,
            'serial' => $this->uniqueSerial(),
            'origin' => 'waiver',
            'status' => 'used',
            'student_id' => $studentId,
            'term_id' => $termId,
            'sold_to' => null,
            'sold_channel' => 'waiver',
            'sold_amount' => 0,
            'sold_at' => now(),
            'first_used_at' => now(),
            'last_used_at' => now(),
            // A waiver is the school saying "this family does not pay for this
            // result". Metering it to five views would just generate a support
            // call in week three.
            'max_views' => 1000,
            'grant_reason' => $reason,
        ]);
        $pin->setCode(ResultPin::generateCode());
        $pin->save();

        return $pin;
    }

    public function availableStock(int $schoolId): int
    {
        return ResultPin::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('status', 'available')
            ->count();
    }

    /**
     * What a guardian pays this school for one PIN.
     *
     * Null means the school has not set a retail price, which callers must
     * treat as "not selling" — falling back to the wholesale rate would have
     * SchoolPilot silently setting a school's prices for it.
     */
    public function retailPrice(School $school): ?string
    {
        if (! $school->result_checker_enabled) {
            return null;
        }

        return $school->result_pin_retail_price !== null
            ? (string) $school->result_pin_retail_price
            : null;
    }

    private function maxViews(): int
    {
        return (int) config('schoolpilot.result_pin_max_views', 5);
    }

    private function uniqueSerial(): string
    {
        do {
            $serial = ResultPin::generateSerial();
        } while (ResultPin::withoutGlobalScopes()->where('serial', $serial)->exists());

        return $serial;
    }

    public function generateReference(string $prefix): string
    {
        return $prefix . '_' . now()->format('YmdHis') . '_' . strtoupper(Str::random(8));
    }
}
