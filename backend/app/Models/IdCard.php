<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An issued ID card. See the migration for why this is a durable row.
 */
class IdCard extends Model
{
    use HasFactory, BelongsToTenant;

    public const HOLDER_TYPES = ['student', 'staff'];

    /**
     * Revocation reasons, as categories.
     *
     * A fixed list rather than free text because the verification page is
     * public — anyone who scans the card reads this. "lost" is a fact about a
     * piece of plastic; a sentence typed by a bursar at 4pm is a fact about a
     * child, published to whoever picks the card up.
     */
    public const REVOCATION_REASONS = ['lost', 'stolen', 'damaged', 'left_school', 'data_error', 'other'];

    protected $fillable = [
        'school_id',
        'holder_type',
        'holder_id',
        'serial',
        'verify_token',
        'session_id',
        'issued_on',
        'expires_on',
        'status',
        'revoked_at',
        'revoked_reason',
        'revoked_by',
        'replaces_id',
        'print_count',
        'last_printed_at',
        'template_id',
        'template_checksum',
        'photo_fingerprint',
        'issued_by',
    ];

    protected $casts = [
        'issued_on' => 'date',
        'expires_on' => 'date',
        'revoked_at' => 'datetime',
        'last_printed_at' => 'datetime',
        'print_count' => 'integer',
    ];

    /**
     * The token never serialises.
     *
     * It is a bearer value for the public verification page. An admin listing
     * of a class's cards has no use for it, and any `response()->json($card)`
     * that leaked it would hand out a permanent, unauthenticated read of that
     * child's identity record. Code that needs it (the QR encoder, the
     * verification lookup) reads the attribute directly.
     */
    protected $hidden = ['verify_token'];

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function replaces()
    {
        return $this->belongsTo(self::class, 'replaces_id');
    }

    public function template()
    {
        return $this->belongsTo(IdCardTemplate::class, 'template_id');
    }

    public function session()
    {
        return $this->belongsTo(AcademicSession::class, 'session_id');
    }

    /**
     * The Student or Staff row this card was issued to.
     *
     * Resolved by hand rather than through morphTo so the tenant bound stays
     * visible at the call site — a card and its holder must belong to the same
     * school, and a relation that silently crosses that is the bug this
     * codebase's global scopes exist to prevent.
     */
    public function holder(): ?Model
    {
        $query = match ($this->holder_type) {
            'student' => Student::withoutGlobalScopes()->with(['user', 'currentClass', 'currentArm']),
            'staff' => Staff::withoutGlobalScopes()->with('user'),
            default => null,
        };

        return $query?->where('school_id', $this->school_id)->find($this->holder_id);
    }

    /**
     * Status as of now, including expiry that no job has got round to writing.
     *
     * `status` is the stored intent; this is the truth. A card that expired on
     * Friday is not valid on Saturday whether or not the nightly sweep has run
     * yet, and the verification page must never be the thing that waits for a
     * cron before telling a gatekeeper the truth.
     */
    public function effectiveStatus(): string
    {
        if ($this->status !== 'active') {
            return $this->status;
        }

        if ($this->expires_on && $this->expires_on->endOfDay()->isPast()) {
            return 'expired';
        }

        return 'active';
    }

    public function isValid(): bool
    {
        return $this->effectiveStatus() === 'active';
    }

    public function scopeValid($query)
    {
        return $query->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('expires_on')->orWhereDate('expires_on', '>=', Carbon::today());
            });
    }

    public function scopeForHolder($query, string $holderType, int $holderId)
    {
        return $query->where('holder_type', $holderType)->where('holder_id', $holderId);
    }
}