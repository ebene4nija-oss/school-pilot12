<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What a school rates on its report cards and which comments it prints.
 * One row per school; see the migration. `forSchool()` fills in defaults so a
 * school that has not set anything still gets a sensible form.
 */
class SchoolReportSettings extends Model
{
    use BelongsToTenant;

    protected $table = 'school_report_settings';

    protected $fillable = [
        'school_id', 'affective_traits', 'psychomotor_skills', 'rating_scale',
        'form_teacher_comment', 'principal_comment', 'principal_title', 'updated_by',
    ];

    protected $casts = [
        'affective_traits' => 'array',
        'psychomotor_skills' => 'array',
        'rating_scale' => 'array',
        'form_teacher_comment' => 'boolean',
        'principal_comment' => 'boolean',
    ];

    public const DEFAULT_AFFECTIVE = ['Punctuality', 'Neatness', 'Politeness', 'Honesty', 'Attentiveness', 'Relationship with others'];

    public const DEFAULT_PSYCHOMOTOR = ['Handwriting', 'Sports', 'Drawing and painting', 'Musical skills'];

    /** Best first. */
    public const DEFAULT_SCALE = [
        ['value' => '5', 'label' => 'Excellent'],
        ['value' => '4', 'label' => 'Very good'],
        ['value' => '3', 'label' => 'Good'],
        ['value' => '2', 'label' => 'Fair'],
        ['value' => '1', 'label' => 'Poor'],
    ];

    /**
     * The school's settings, or unsaved defaults. `exists` tells a caller
     * whether the school has actually chosen (the setup checklist uses it).
     */
    public static function forSchool(int $schoolId): self
    {
        return static::withoutGlobalScopes()->where('school_id', $schoolId)->first() ?? new self([
            'school_id' => $schoolId,
            'affective_traits' => self::DEFAULT_AFFECTIVE,
            'psychomotor_skills' => self::DEFAULT_PSYCHOMOTOR,
            'rating_scale' => self::DEFAULT_SCALE,
            'form_teacher_comment' => true,
            'principal_comment' => true,
            'principal_title' => 'Principal',
        ]);
    }

    /** Every rated item, in order: [{name, group}]. */
    public function items(): array
    {
        return array_merge(
            array_map(fn ($n) => ['name' => $n, 'group' => 'affective'], $this->affective_traits ?? []),
            array_map(fn ($n) => ['name' => $n, 'group' => 'psychomotor'], $this->psychomotor_skills ?? []),
        );
    }

    public function ratingLabel(?string $value): ?string
    {
        foreach ($this->rating_scale ?? [] as $option) {
            if ((string) $option['value'] === (string) $value) {
                return $option['label'] ?? null;
            }
        }

        return null;
    }
}
