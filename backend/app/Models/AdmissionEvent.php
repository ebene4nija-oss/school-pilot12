<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** An application's decision history. Append-only. */
class AdmissionEvent extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = ['school_id', 'application_id', 'action', 'from_status', 'to_status', 'user_id', 'note'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Admission events are append-only.'));
        static::deleting(fn () => throw new LogicException('Admission events are append-only.'));
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
