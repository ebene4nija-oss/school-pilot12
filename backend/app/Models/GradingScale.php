<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A school's grading scale for one class level (see the migration).
 *
 * Bands are whole-number percentage ranges that must cover 0 to 100 with no
 * gap and no overlap — a mark that falls between two bands would print no
 * grade at all on a report card.
 */
class GradingScale extends Model
{
    use BelongsToTenant;

    /** `default` covers any level without a scale of its own. */
    public const LEVELS = [
        'default' => 'All classes',
        'nursery' => 'Nursery',
        'primary' => 'Primary',
        'junior_secondary' => 'Junior secondary (JSS)',
        'senior_secondary' => 'Senior secondary (SS)',
    ];

    /** WAEC nine-point scale: the fallback when a school has set nothing. */
    public const WAEC = [
        ['min' => 75, 'max' => 100, 'grade' => 'A1', 'remark' => 'Excellent'],
        ['min' => 70, 'max' => 74, 'grade' => 'B2', 'remark' => 'Very Good'],
        ['min' => 65, 'max' => 69, 'grade' => 'B3', 'remark' => 'Good'],
        ['min' => 60, 'max' => 64, 'grade' => 'C4', 'remark' => 'Credit'],
        ['min' => 55, 'max' => 59, 'grade' => 'C5', 'remark' => 'Credit'],
        ['min' => 50, 'max' => 54, 'grade' => 'C6', 'remark' => 'Credit'],
        ['min' => 45, 'max' => 49, 'grade' => 'D7', 'remark' => 'Pass'],
        ['min' => 40, 'max' => 44, 'grade' => 'E8', 'remark' => 'Pass'],
        ['min' => 0, 'max' => 39, 'grade' => 'F9', 'remark' => 'Fail'],
    ];

    protected $fillable = ['school_id', 'level', 'name', 'bands', 'updated_by'];

    protected $casts = ['bands' => 'array'];

    /**
     * The bands for a class level at a school: that level's own scale, else
     * the school's default, else WAEC.
     *
     * @return array<int, array{min: int, max: int, grade: string, remark: string|null}>
     */
    public static function bandsFor(?int $schoolId, ?string $level): array
    {
        if ($schoolId === null) {
            return self::WAEC;
        }

        $level = self::normaliseLevel($level);

        $scales = static::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->whereIn('level', array_values(array_filter([$level, 'default'])))
            ->get()
            ->keyBy('level');

        $scale = ($level ? $scales->get($level) : null) ?? $scales->get('default');

        return $scale ? self::sorted($scale->bands) : self::WAEC;
    }

    /**
     * Class levels are stored loosely (`secondary` is the column default), so
     * anything that is not a known level falls through to the default scale.
     */
    public static function normaliseLevel(?string $level): ?string
    {
        return $level !== null && $level !== 'default' && isset(self::LEVELS[$level]) ? $level : null;
    }

    /**
     * Problems with a set of bands, as sentences; empty when valid.
     *
     * @param  array<int, mixed>  $bands
     * @return array<int, string>
     */
    public static function problems(array $bands): array
    {
        if ($bands === []) {
            return ['Add at least one grade.'];
        }

        $problems = [];
        $seen = [];

        foreach (array_values($bands) as $i => $band) {
            $n = $i + 1;
            $min = $band['min'] ?? null;
            $max = $band['max'] ?? null;
            $grade = trim((string) ($band['grade'] ?? ''));

            if ($grade === '') {
                $problems[] = "Row {$n} has no grade.";
            } elseif (isset($seen[strtoupper($grade)])) {
                $problems[] = "Grade {$grade} appears twice.";
            }
            $seen[strtoupper($grade)] = true;

            if (! is_numeric($min) || ! is_numeric($max) || floor((float) $min) != $min || floor((float) $max) != $max) {
                $problems[] = "Row {$n} needs whole-number lowest and highest marks.";

                continue;
            }

            if ($min < 0 || $max > 100 || $min > $max) {
                $problems[] = "Row {$n}: {$min} to {$max} is not a range between 0 and 100.";
            }
        }

        if ($problems !== []) {
            return $problems;
        }

        // Walk down from 100: each band must end one below where the last began.
        $expectedMax = 100;

        foreach (self::sorted($bands) as $band) {
            if ($band['max'] > $expectedMax) {
                return ["{$band['grade']} ({$band['min']} to {$band['max']}) overlaps the grade above it."];
            }

            if ($band['max'] < $expectedMax) {
                return ['No grade covers '.($band['max'] + 1)." to {$expectedMax}."];
            }

            $expectedMax = $band['min'] - 1;
        }

        return $expectedMax === -1 ? [] : ["No grade covers 0 to {$expectedMax}."];
    }

    /**
     * Normalised bands, highest first.
     *
     * @return array<int, array{min: int, max: int, grade: string, remark: string|null}>
     */
    public static function sorted(array $bands): array
    {
        $bands = array_map(fn ($b) => [
            'min' => (int) $b['min'],
            'max' => (int) $b['max'],
            'grade' => trim((string) $b['grade']),
            'remark' => isset($b['remark']) && trim((string) $b['remark']) !== '' ? trim((string) $b['remark']) : null,
        ], array_values($bands));

        usort($bands, fn ($a, $b) => $b['max'] <=> $a['max']);

        return $bands;
    }

    /**
     * The band a percentage falls in. Marks are rounded to the nearest whole
     * number first, so 74.6 is 75 — the band edges are whole numbers.
     *
     * @return array{min: int, max: int, grade: string, remark: string|null}
     */
    public static function bandOf(array $bands, float $percentage): array
    {
        $mark = (int) round(max(0, min(100, $percentage)));

        foreach ($bands as $band) {
            if ($mark >= $band['min'] && $mark <= $band['max']) {
                return $band;
            }
        }

        return end($bands) ?: ['min' => 0, 'max' => 100, 'grade' => '', 'remark' => null];
    }
}
