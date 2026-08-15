<?php

namespace App\Services;

use App\Models\ResultPinPriceTier;
use App\Models\ResultPinSchoolRate;

/**
 * What one school pays SchoolPilot for one PIN.
 *
 * Three places need this answer — the quote a school sees, the batch it buys
 * online, and the batch an operator grants it offline — and they must not drift:
 * a school quoted ₦75 and charged ₦100 at checkout is a support call and a
 * refund. So the precedence lives here once, and nowhere else.
 *
 *   1. The school's negotiated flat rate, if one is active.
 *   2. The published quantity-banded rate card.
 *   3. Nothing — SchoolPilot has published no card at all.
 *
 * A negotiated rate deliberately wins over the volume bands even when the card
 * would be cheaper on a very large order. It is a hand-shaken number, and
 * silently charging a school something other than what was agreed is worse than
 * charging it slightly too much on one unusual order. Operators granting a batch
 * can still type an explicit price to beat both.
 */
final class ResultPinPricing
{
    public const SOURCE_NEGOTIATED = 'negotiated';
    public const SOURCE_RATE_CARD = 'rate_card';

    /**
     * Resolve a unit price for a school buying `quantity` PINs.
     *
     * `unit_price` is null when nothing is configured, which callers must treat
     * as "selling is not set up" rather than free — a zero here would hand out
     * unlimited free inventory.
     *
     * @return array{unit_price: ?string, source: ?string, negotiated: bool}
     */
    public static function quote(?int $schoolId, int $quantity): array
    {
        $rate = ResultPinSchoolRate::activeFor($schoolId);

        if ($rate) {
            return [
                'unit_price' => (string) $rate->unit_price,
                'source' => self::SOURCE_NEGOTIATED,
                'negotiated' => true,
            ];
        }

        $cardPrice = ResultPinPriceTier::priceFor($quantity);

        return [
            'unit_price' => $cardPrice === null ? null : (string) $cardPrice,
            'source' => $cardPrice === null ? null : self::SOURCE_RATE_CARD,
            'negotiated' => false,
        ];
    }

    /** The unit price alone, for callers that do not care where it came from. */
    public static function unitPrice(?int $schoolId, int $quantity): ?string
    {
        return self::quote($schoolId, $quantity)['unit_price'];
    }
}
