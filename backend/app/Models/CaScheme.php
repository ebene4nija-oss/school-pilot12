<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * How a school splits continuous assessment against the exam.
 *
 * Doc §7.7 is explicit that this must be flexible: "schools each weight CA vs.
 * exams differently — this must be flexible, not fixed." The table has existed
 * since the first assessment migration but nothing read it; scoring was
 * hardcoded to 20/20/60 in the controller's validation rules, so a school
 * running 30/30/40 could not be onboarded without a code change.
 */
class CaScheme extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'ca_schemes';

    protected $fillable = [
        'school_id',
        'name',
        'first_ca_weight',
        'second_ca_weight',
        'exam_weight',
        'is_default',
    ];

    protected $casts = [
        'first_ca_weight' => 'integer',
        'second_ca_weight' => 'integer',
        'exam_weight' => 'integer',
        'is_default' => 'boolean',
    ];

    /**
     * The scheme a school marks against, falling back to the Nigerian
     * convention when a school has not configured one.
     *
     * Returned unsaved when absent: reading a broadsheet should not quietly
     * write a row, and a school that never visits the settings screen still
     * needs its scores to total correctly.
     */
    public static function activeFor(int $schoolId): self
    {
        $scheme = static::where('school_id', $schoolId)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();

        return $scheme ?? new self([
            'school_id' => $schoolId,
            'name' => 'Standard 20/20/60',
            'first_ca_weight' => 20,
            'second_ca_weight' => 20,
            'exam_weight' => 60,
            'is_default' => true,
        ]);
    }

    public function totalWeight(): int
    {
        return $this->first_ca_weight + $this->second_ca_weight + $this->exam_weight;
    }
}
