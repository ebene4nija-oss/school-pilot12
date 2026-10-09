<?php

namespace App\Models;

use App\Support\Sections;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * How a school's broadsheet is calculated and laid out, per level group with
 * a school-wide fallback. Every default here is the Nigerian convention the
 * product already used before this was configurable.
 */
class BroadsheetConfiguration extends Model
{
    use BelongsToTenant;

    public const DEFAULTS = [
        'display_mode' => 'score_grade',
        'ranking_basis' => 'average',
        'ranking_method' => 'competition',
        'ranking_scope' => 'all',
        // class | arm | stream; null = the school's default_ranking_group
        'ranking_group' => null,
        'absence_policy' => 'exclude',
        'pass_mark' => 40,
        'show_gender' => false,
        'show_attendance' => true,
        'show_remarks' => false,
        'numeric_averages' => true,
        'subject_order' => null,
        'confidentiality_footer' => null,
    ];

    protected $fillable = [
        'school_id',
        'level_group',
        'display_mode',
        'ranking_basis',
        'ranking_method',
        'ranking_scope',
        'ranking_group',
        'absence_policy',
        'pass_mark',
        'show_gender',
        'show_attendance',
        'show_remarks',
        'numeric_averages',
        'subject_order',
        'confidentiality_footer',
    ];

    protected $casts = [
        'pass_mark' => 'integer',
        'show_gender' => 'boolean',
        'show_attendance' => 'boolean',
        'show_remarks' => 'boolean',
        'numeric_averages' => 'boolean',
        'subject_order' => 'array',
    ];

    /**
     * The effective settings for a level group: its own row, else the
     * school's default row, else the defaults above. Nursery averages and
     * positions are off unless a school turns them on (broadsheet spec §7).
     *
     * @return array<string, mixed>
     */
    public static function resolve(int $schoolId, ?string $levelGroup): array
    {
        $rows = static::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where(fn ($q) => $q->whereNull('level_group')->orWhere('level_group', $levelGroup))
            ->get()
            ->keyBy(fn ($r) => $r->level_group ?? '_default');

        $row = ($levelGroup !== null ? $rows->get($levelGroup) : null) ?? $rows->get('_default');

        $defaults = self::DEFAULTS;

        if ($levelGroup === 'nursery') {
            $defaults['numeric_averages'] = false;
            $defaults['ranking_basis'] = 'none';
        }

        $resolved = $row ? array_merge($defaults, array_intersect_key($row->toArray(), $defaults)) : $defaults;

        // Schools created before ranking groups existed keep whole-class
        // positions; new ones rank within the arm.
        $resolved['ranking_group'] ??= DB::table('schools')->where('id', $schoolId)->value('default_ranking_group') ?? 'class';

        return $resolved + ['level_group' => $levelGroup];
    }

    public static function levelGroupValid(?string $levelGroup): bool
    {
        return $levelGroup === null || isset(Sections::LEVEL_GROUPS[$levelGroup]);
    }
}
