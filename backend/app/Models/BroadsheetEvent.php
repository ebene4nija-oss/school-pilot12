<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * The broadsheet's history. Append-only: an update or delete throws, so a
 * dispute is always answered by the log as it was written.
 */
class BroadsheetEvent extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = [
        'school_id',
        'broadsheet_id',
        'action',
        'step',
        'step_role',
        'department',
        'user_id',
        'reason',
        'detail',
    ];

    protected $casts = ['detail' => 'array'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Broadsheet events are append-only.'));
        static::deleting(fn () => throw new LogicException('Broadsheet events are append-only.'));
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
