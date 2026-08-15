<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CbtAttempt extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'cbt_attempts';

    protected $fillable = [
        'school_id',
        'exam_id',
        'student_id',
        'attempt_number',
        'seed',
        'question_order',
        'status',
        'started_at',
        'server_deadline_at',
        'submitted_at',
        'graded_at',
        'extra_time_minutes',
        'raw_score',
        'max_score',
        'percentage',
        'grade',
        'time_spent_seconds',
        'requires_manual_grading',
        'integrity_flags',
        'device_fingerprint',
        'ip_address',
        'offline_bundle_id',
        'order_is_final',
    ];

    protected $casts = [
        'question_order' => 'array',
        'integrity_flags' => 'array',
        'started_at' => 'datetime',
        'server_deadline_at' => 'datetime',
        'submitted_at' => 'datetime',
        'graded_at' => 'datetime',
        'seed' => 'integer',
        'attempt_number' => 'integer',
        'extra_time_minutes' => 'integer',
        'time_spent_seconds' => 'integer',
        'requires_manual_grading' => 'boolean',
        'raw_score' => 'decimal:2',
        'max_score' => 'decimal:2',
        'percentage' => 'decimal:2',
        'order_is_final' => 'boolean',
    ];

    /**
     * A paper pre-issued into an offline bundle that nobody has sat yet.
     *
     * It exists so the relay can hand a named candidate their attempt id and
     * question order with no network. It is deliberately *not* `in_progress`:
     * a provisioned attempt must not burn an allowance against `max_attempts`,
     * must not be swept by the overdue-attempt sweeper, and must be
     * reclaimable if the exam is cancelled.
     */
    public const STATUS_PROVISIONED = 'provisioned';

    public function exam()
    {
        return $this->belongsTo(CbtExam::class, 'exam_id');
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function answers()
    {
        return $this->hasMany(CbtAttemptAnswer::class, 'attempt_id');
    }

    public function events()
    {
        return $this->hasMany(CbtAttemptEvent::class, 'attempt_id');
    }

    public function isFinalised(): bool
    {
        return in_array($this->status, ['submitted', 'graded', 'expired', 'voided'], true);
    }

    public function isProvisioned(): bool
    {
        return $this->status === self::STATUS_PROVISIONED;
    }

    /**
     * Server clock is authoritative. A client that reports 40 minutes
     * remaining when the server says 0 gets 0.
     */
    public function secondsRemaining(?\DateTimeInterface $now = null): int
    {
        if (!$this->server_deadline_at) {
            return 0;
        }

        $now = $now ?? now();
        $remaining = $this->server_deadline_at->getTimestamp() - $now->getTimestamp();

        return max(0, $remaining);
    }

    public function hasExpired(?\DateTimeInterface $now = null): bool
    {
        return $this->server_deadline_at !== null && $this->secondsRemaining($now) === 0;
    }
}
