<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** A transfer's status history. Append-only. */
class TransferEvent extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = ['school_id', 'transfer_id', 'action', 'from_status', 'to_status', 'user_id', 'note', 'detail'];

    protected $casts = ['detail' => 'array'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Transfer events are append-only.'));
        static::deleting(fn () => throw new LogicException('Transfer events are append-only.'));
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
