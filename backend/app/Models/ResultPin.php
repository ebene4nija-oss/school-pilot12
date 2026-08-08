<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * A single result-checker PIN.
 *
 * Stock until spent. On first use it binds permanently to one student and one
 * term and starts burning views; it can never be re-pointed at another child,
 * which is the whole reason the binding lives on the PIN row rather than in a
 * separate grants table that could accumulate a second row for a second child.
 */
class ResultPin extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'result_pins';

    protected $fillable = [
        'school_id',
        'batch_id',
        'serial',
        'pin_hash',
        'pin_secret',
        'origin',
        'status',
        'student_id',
        'term_id',
        'views_used',
        'max_views',
        'sold_to',
        'sold_channel',
        'sold_amount',
        'sold_at',
        'first_used_at',
        'last_used_at',
        'voided_by',
        'void_reason',
        'grant_reason',
    ];

    protected $casts = [
        'views_used' => 'integer',
        'max_views' => 'integer',
        'sold_amount' => 'decimal:2',
        'sold_at' => 'datetime',
        'first_used_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    /**
     * Never serialize the code material.
     *
     * `pin_secret` is decryptable and `pin_hash` is a redemption lookup key —
     * either one leaking through a stray `->toJson()` on a listing endpoint
     * hands out free results. Reveal goes through `revealCode()` at the one
     * call site that is allowed to do it.
     */
    protected $hidden = [
        'pin_hash',
        'pin_secret',
    ];

    /*
     * Alphabet with 0/O/1/I/L/5/S removed. These get read aloud over the phone
     * to a parent and copied off a printed slip, and the ambiguous pairs are
     * where support tickets come from.
     */
    private const ALPHABET = '2346789ABCDEFGHJKMNPQRTUVWXYZ';

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function term()
    {
        return $this->belongsTo(Term::class);
    }

    public function batch()
    {
        return $this->belongsTo(ResultPinBatch::class, 'batch_id');
    }

    public function soldTo()
    {
        return $this->belongsTo(User::class, 'sold_to');
    }

    /**
     * Keyed hash used to find a PIN on redemption.
     *
     * HMAC rather than bcrypt because redemption needs an indexed lookup — a
     * bcrypt column would force a scan-and-verify over every unsold PIN in the
     * school. Safe here only because the code is 60 bits of randomness from
     * `generateCode()`, not a user-chosen secret: there is nothing to enumerate.
     */
    public static function hashCode(string $code): string
    {
        return hash_hmac('sha256', static::normalizeCode($code), (string) config('app.key'));
    }

    /**
     * Accept what a human actually types: spaces, dashes, lowercase.
     */
    public static function normalizeCode(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    }

    /** 12 characters of the unambiguous alphabet, grouped for readability. */
    public static function generateCode(): string
    {
        $raw = '';
        for ($i = 0; $i < 12; $i++) {
            $raw .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return implode('-', str_split($raw, 4));
    }

    /** Non-secret handle, safe to print in receipts, lists and logs. */
    public static function generateSerial(): string
    {
        return 'SP-' . strtoupper(Str::random(4)) . '-' . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * The plaintext code, for the one place allowed to show it: a bursar
     * selling over the counter, or the receipt at the moment of purchase.
     */
    public function revealCode(): string
    {
        return Crypt::decryptString($this->pin_secret);
    }

    public function setCode(string $code): void
    {
        $this->pin_hash = static::hashCode($code);
        $this->pin_secret = Crypt::encryptString(static::normalizeCode($code));
    }

    public function viewsRemaining(): int
    {
        return max(0, $this->max_views - $this->views_used);
    }

    /**
     * Whether this PIN currently opens the given result.
     *
     * Requires an exact binding: a PIN bound to another child, another term,
     * or exhausted of views does not.
     */
    public function grantsAccessTo(int $studentId, int $termId): bool
    {
        return $this->status === 'used'
            && (int) $this->student_id === $studentId
            && (int) $this->term_id === $termId
            && $this->viewsRemaining() > 0;
    }
}
