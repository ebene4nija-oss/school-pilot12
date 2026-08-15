<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IdCardTemplate extends Model
{
    use HasFactory, BelongsToTenant;

    /** Contract version this build renders. Bundles must declare it. */
    public const ENGINE = 'schoolpilot-id-card/v1';

    public const HOLDER_TYPES = ['student', 'staff'];

    protected $fillable = [
        'school_id',
        'name',
        'slug',
        'version',
        'description',
        'engine',
        'holder_type',
        'front',
        'back',
        'styles',
        'card_geometry',
        'regions',
        'validation_report',
        'checksum',
        'status',
        'imported_by',
        'imported_at',
    ];

    protected $casts = [
        'card_geometry' => 'array',
        'regions' => 'array',
        'validation_report' => 'array',
        'version' => 'integer',
        'imported_at' => 'datetime',
    ];

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function isDoubleSided(): bool
    {
        return trim((string) $this->back) !== '';
    }

    /**
     * Make this the live design for its holder type.
     *
     * Scoped to holder_type rather than to the school: activating a new staff
     * card must not quietly archive the student design and leave the next
     * student print run falling back to the shipped default.
     */
    public function activate(): void
    {
        static::withoutGlobalScopes()
            ->where('school_id', $this->school_id)
            ->where('holder_type', $this->holder_type)
            ->where('id', '!=', $this->id)
            ->where('status', 'active')
            ->update(['status' => 'archived']);

        $this->update(['status' => 'active']);
    }
}