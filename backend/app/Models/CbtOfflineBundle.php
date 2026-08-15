<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One encrypted paper, issued to one lab relay.
 *
 * The row is the server's memory of an issuance: which exam, which candidates
 * were on the roster, who took delivery, and whether the key has been released
 * yet. The ciphertext itself is not stored — it is built, handed to the relay
 * once, and rebuilt from scratch if a second copy is ever needed.
 *
 * `content_key` is encrypted at rest with the app key. That is not theatre:
 * the whole design rests on question text sitting on a lab laptop overnight as
 * ciphertext whose key is not on that machine, so a database dump that also
 * handed over every content key would collapse the model.
 */
class CbtOfflineBundle extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'cbt_offline_bundles';

    protected $fillable = [
        'school_id',
        'exam_id',
        'bundle_id',
        'key_id',
        'format_version',
        'content_key',
        'attempt_ids',
        'question_count',
        'attempt_count',
        'ciphertext_bytes',
        'relay_identity',
        'built_by',
        'built_at',
        'unlocked_at',
        'unlocked_by',
        'revoked_at',
    ];

    protected $casts = [
        'content_key' => 'encrypted',
        'attempt_ids' => 'array',
        'format_version' => 'integer',
        'question_count' => 'integer',
        'attempt_count' => 'integer',
        'ciphertext_bytes' => 'integer',
        'built_at' => 'datetime',
        'unlocked_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /** The key is a secret with an endpoint of its own; it never serialises. */
    protected $hidden = ['content_key'];

    public function exam()
    {
        return $this->belongsTo(CbtExam::class, 'exam_id');
    }

    public function attempts()
    {
        return $this->hasMany(CbtAttempt::class, 'offline_bundle_id');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Is this attempt on the roster this bundle was built for?
     *
     * The check that stops a relay posting answers for a candidate it was
     * never given — a staff token plus a guessed attempt id would otherwise be
     * a write path into any child's paper in the school.
     */
    public function covers(int $attemptId): bool
    {
        return in_array($attemptId, array_map('intval', $this->attempt_ids ?? []), true);
    }
}
