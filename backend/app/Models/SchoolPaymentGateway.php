<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One school's credentials for one payment gateway (gap G8).
 *
 * The three key columns are cast `encrypted`, so they are ciphertext at rest and
 * a stolen database dump is not a set of live merchant keys. That protects the
 * dump and nothing else — Eloquent decrypts on access — so `$hidden` keeps them
 * out of any accidental `response()->json($gateway)`. Nothing in the codebase
 * should ever serialise this model to a client: the settings endpoint builds its
 * own masked payload, and the only other reader is PaymentGatewayService, which
 * reads the attributes directly and sends them to the gateway, never to us.
 */
class SchoolPaymentGateway extends Model
{
    use BelongsToTenant;

    public const GATEWAYS = ['paystack', 'flutterwave'];

    protected $fillable = [
        'school_id',
        'gateway',
        'public_key',
        'secret_key',
        'webhook_secret',
        'secret_last4',
        'mode',
        'is_active',
        'verified_at',
        'updated_by',
    ];

    protected $casts = [
        'public_key' => 'encrypted',
        'secret_key' => 'encrypted',
        'webhook_secret' => 'encrypted',
        'is_active' => 'boolean',
        'verified_at' => 'datetime',
    ];

    protected $hidden = [
        'public_key',
        'secret_key',
        'webhook_secret',
    ];

    /** Usable for taking money: switched on and holding a secret. */
    public function isUsable(): bool
    {
        return $this->is_active && is_string($this->secret_key) && trim($this->secret_key) !== '';
    }

    /**
     * What the school's dashboard signs with.
     *
     * Paystack signs the webhook body with the secret key itself; Flutterwave
     * compares a static hash the merchant chooses. Keeping the difference here
     * means the webhook handler asks one question of both gateways.
     */
    public function signingSecret(): ?string
    {
        return $this->gateway === 'flutterwave'
            ? $this->webhook_secret
            : $this->secret_key;
    }
}
