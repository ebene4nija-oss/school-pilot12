<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Someone who brings schools to SchoolPilot and earns a lifetime commission
 * on the term bills those schools pay. Platform-level: no tenant scope.
 *
 * `commission_rate_override` is the rate negotiated with this partner. It is
 * hidden from serialisation so no partner-facing or public response can carry
 * it by accident; only the operator console reads it, explicitly.
 */
class Partner extends Model
{
    public const STATUSES = ['pending', 'active', 'suspended'];

    protected $fillable = [
        'user_id', 'code', 'name', 'email', 'phone', 'status',
        'commission_rate_override', 'bank_name', 'account_number', 'account_name',
        'pitch', 'approved_at',
    ];

    protected $hidden = ['commission_rate_override'];

    protected $casts = [
        'commission_rate_override' => 'integer',
        'bank_name' => 'encrypted',
        'account_number' => 'encrypted',
        'account_name' => 'encrypted',
        'approved_at' => 'datetime',
    ];

    /** The rate this partner earns on a bill paid now, in basis points. */
    public function rateBps(): int
    {
        return $this->commission_rate_override ?? (int) config('services.partners.default_rate_bps');
    }

    /** A short, unambiguous referral code that is not already taken. */
    public static function newCode(): string
    {
        do {
            $code = 'P'.strtoupper(Str::random(6));
            $code = strtr($code, ['O' => '7', '0' => '8', 'I' => '9', '1' => '6']);
        } while (static::where('code', $code)->exists());

        return $code;
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function schools()
    {
        return $this->hasMany(School::class);
    }

    public function commissions()
    {
        return $this->hasMany(PartnerCommission::class);
    }

    public function payouts()
    {
        return $this->hasMany(PartnerPayout::class);
    }
}
