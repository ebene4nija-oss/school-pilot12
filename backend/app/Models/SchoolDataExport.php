<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SchoolDataExport extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'school_id',
        'requested_by',
        'status',
        'file_path',
        'file_size',
        'row_counts',
        'includes_medical',
        'error',
        'completed_at',
        'expires_at',
    ];

    protected $casts = [
        'row_counts' => 'array',
        'includes_medical' => 'boolean',
        'completed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isDownloadable(): bool
    {
        return $this->status === 'complete'
            && $this->file_path !== null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
