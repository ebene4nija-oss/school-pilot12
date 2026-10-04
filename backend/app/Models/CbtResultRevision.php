<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** One revision of a CBT result, with the rules used. Append-only. */
class CbtResultRevision extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = ['school_id', 'attempt_id', 'revision', 'raw_score', 'max_score', 'percentage', 'grade', 'rules', 'reason', 'regrade_job_id', 'created_by'];

    protected $casts = ['rules' => 'array'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Result revisions are append-only.'));
        static::deleting(fn () => throw new LogicException('Result revisions are append-only.'));
    }
}
