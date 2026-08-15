<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IdCardPrintRun extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'school_id',
        'holder_type',
        'filters',
        'layout',
        'template_id',
        'status',
        'preflight_report',
        'card_count',
        'sheet_count',
        'pdf_path',
        'pdf_bytes',
        'expires_at',
        'error',
        'requested_by',
        'completed_at',
    ];

    protected $casts = [
        'filters' => 'array',
        'layout' => 'array',
        'preflight_report' => 'array',
        'card_count' => 'integer',
        'sheet_count' => 'integer',
        'pdf_bytes' => 'integer',
        'expires_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * How long a rendered sheet set stays downloadable.
     *
     * Seven days is a print window, not an archive. The PDF holds every child
     * in a class — name and face on one page — so it is the single most
     * sensitive file this product writes to disk, and keeping it past the
     * point of use is collection without purpose (doc §12). The issuance rows
     * are the permanent record; the artefact is not.
     */
    public const RETENTION_DAYS = 7;

    public function template()
    {
        return $this->belongsTo(IdCardTemplate::class, 'template_id');
    }

    public function isDownloadable(): bool
    {
        return $this->status === 'completed'
            && $this->pdf_path
            && (!$this->expires_at || $this->expires_at->isFuture());
    }
}