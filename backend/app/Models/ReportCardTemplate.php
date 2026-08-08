<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReportCardTemplate extends Model
{
    use HasFactory, BelongsToTenant;

    /** Contract version this build renders. Bundles must declare it. */
    public const ENGINE = 'schoolpilot-report-card/v1';

    protected $fillable = [
        'school_id',
        'name',
        'slug',
        'version',
        'description',
        'engine',
        'body',
        'styles',
        'page_settings',
        'regions',
        'validation_report',
        'checksum',
        'status',
        'imported_by',
        'imported_at',
    ];

    protected $casts = [
        'page_settings' => 'array',
        'regions' => 'array',
        'validation_report' => 'array',
        'version' => 'integer',
        'imported_at' => 'datetime',
    ];

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Exactly one active template per school. Activating a new one archives
     * whatever was live, so a term's report cards can never be generated from
     * two different designs.
     */
    public function activate(): void
    {
        static::where('school_id', $this->school_id)
            ->where('id', '!=', $this->id)
            ->where('status', 'active')
            ->update(['status' => 'archived']);

        $this->update(['status' => 'active']);
    }
}
