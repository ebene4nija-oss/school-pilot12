<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The signed, expiring copy of a student's records prepared for a transfer. */
class TransferPackage extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'school_id', 'transfer_id', 'payload', 'payload_hash', 'signature', 'link_token_hash',
        'expires_at', 'superseded_at', 'superseded_reason', 'access_count', 'created_by',
    ];

    protected $casts = ['expires_at' => 'datetime', 'superseded_at' => 'datetime'];

    protected $hidden = ['payload', 'signature', 'link_token_hash'];

    public function content(): array
    {
        return json_decode($this->payload, true) ?? [];
    }

    public function usable(): bool
    {
        return $this->superseded_at === null && $this->expires_at->isFuture();
    }
}
