<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A school declaring a term's results finished and viewable.
 *
 * The paywall hangs off this. Until a release exists, a guardian sees "not yet
 * available" and cannot spend a PIN — the school cannot take money for a report
 * card that is still being marked.
 */
class ResultRelease extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'result_releases';

    protected $fillable = [
        'school_id',
        'term_id',
        'class_id',
        'is_released',
        'released_by',
        'released_at',
    ];

    protected $casts = [
        'is_released' => 'boolean',
        'released_at' => 'datetime',
    ];

    public function term()
    {
        return $this->belongsTo(Term::class);
    }

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    /**
     * Whether this child's term result has been released.
     *
     * A school-wide release (class_id null) covers every class, so a school
     * that publishes everything at once does not have to add a row per class.
     */
    public static function isReleasedFor(int $schoolId, int $termId, ?int $classId): bool
    {
        return static::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('term_id', $termId)
            ->where('is_released', true)
            ->where(function ($query) use ($classId) {
                $query->whereNull('class_id');

                if ($classId !== null) {
                    $query->orWhere('class_id', $classId);
                }
            })
            ->exists();
    }
}
