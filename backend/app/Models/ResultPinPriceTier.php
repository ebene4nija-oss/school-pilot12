<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * SchoolPilot's published wholesale rate for result-checker PINs.
 *
 * Platform-level and therefore deliberately NOT tenant-scoped: every school
 * sees the same rate card. Banded by quantity, so buying in volume is cheaper
 * per PIN.
 */
class ResultPinPriceTier extends Model
{
    use HasFactory;

    protected $table = 'result_pin_price_tiers';

    protected $fillable = [
        'min_quantity',
        'unit_price',
        'is_active',
    ];

    protected $casts = [
        'min_quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    /**
     * The unit price a school pays for `quantity` PINs.
     *
     * Picks the best (highest min_quantity that the order reaches) active band.
     * Returns null when SchoolPilot has published no rate card at all, which
     * callers must treat as "selling is not configured" rather than "free" —
     * defaulting to zero here would hand out unlimited free inventory.
     */
    public static function priceFor(int $quantity): ?string
    {
        $tier = static::where('is_active', true)
            ->where('min_quantity', '<=', $quantity)
            ->orderByDesc('min_quantity')
            ->first();

        return $tier ? $tier->unit_price : null;
    }
}
