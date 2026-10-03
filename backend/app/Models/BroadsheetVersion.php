<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** What was approved, exactly as approved. Never edited after it is written. */
class BroadsheetVersion extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'school_id',
        'broadsheet_id',
        'version',
        'snapshot',
        'snapshot_hash',
        'validation',
        'approved_by',
        'approved_at',
        'superseded_at',
        'superseded_reason',
    ];

    protected $casts = [
        'validation' => 'array',
        'approved_at' => 'datetime',
        'superseded_at' => 'datetime',
    ];

    protected $hidden = ['snapshot'];

    /** @return array<string, mixed> */
    public function data(): array
    {
        return json_decode($this->snapshot, true) ?? [];
    }

    /** Whether the stored snapshot still matches its hash. */
    public function intact(): bool
    {
        return hash_equals($this->snapshot_hash, hash('sha256', $this->snapshot));
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
