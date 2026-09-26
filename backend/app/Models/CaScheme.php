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
        'components',
    ];

    protected $casts = [
        'first_ca_weight' => 'integer',
        'second_ca_weight' => 'integer',
        'exam_weight' => 'integer',
        'is_default' => 'boolean',
        'components' => 'array',
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

    /**
     * The scheme's parts, in order: `[{key, name, max, kind}]`, kind being
     * `ca` or `exam`. A scheme saved before parts existed is read as the
     * classic 1st CA / 2nd CA / Exam.
     *
     * @return array<int, array{key: string, name: string, max: int, kind: string}>
     */
    public function parts(): array
    {
        if (is_array($this->components) && $this->components !== []) {
            return array_values($this->components);
        }

        return [
            ['key' => 'ca1', 'name' => '1st CA', 'max' => (int) $this->first_ca_weight, 'kind' => 'ca'],
            ['key' => 'ca2', 'name' => '2nd CA', 'max' => (int) $this->second_ca_weight, 'kind' => 'ca'],
            ['key' => 'exam', 'name' => 'Exam', 'max' => (int) $this->exam_weight, 'kind' => 'exam'],
        ];
    }

    /** True for the classic two-CA shape, which the legacy fields describe exactly. */
    public function isClassic(): bool
    {
        return array_column($this->parts(), 'key') === ['ca1', 'ca2', 'exam'];
    }

    /**
     * Normalise submitted parts and derive the three legacy weights from them:
     * the first CA, every other CA summed, and the exam parts summed. Keys are
     * assigned here (`ca1`, `ca2`, … and `exam`, `exam2`, …) so a renamed part
     * keeps its marks.
     *
     * @param  array<int, array{name: string, max: int|string, kind?: string}>  $parts
     * @return array{components: array, first_ca_weight: int, second_ca_weight: int, exam_weight: int}
     */
    public static function fromParts(array $parts): array
    {
        $components = [];
        $ca = 0;
        $exam = 0;

        foreach (array_values($parts) as $part) {
            $kind = ($part['kind'] ?? 'ca') === 'exam' ? 'exam' : 'ca';
            $key = $kind === 'exam' ? ($exam++ === 0 ? 'exam' : 'exam'.$exam) : 'ca'.(++$ca);

            $components[] = [
                'key' => $key,
                'name' => trim((string) $part['name']),
                'max' => (int) $part['max'],
                'kind' => $kind,
            ];
        }

        $cas = array_values(array_filter($components, fn ($c) => $c['kind'] === 'ca'));
        $exams = array_filter($components, fn ($c) => $c['kind'] === 'exam');

        return [
            'components' => $components,
            'first_ca_weight' => (int) ($cas[0]['max'] ?? 0),
            'second_ca_weight' => (int) array_sum(array_column(array_slice($cas, 1), 'max')),
            'exam_weight' => (int) array_sum(array_column($exams, 'max')),
        ];
    }

    /**
     * Legacy fields from a mark per part: the first CA, the other CAs summed,
     * and the exam parts summed.
     *
     * @param  array<string, float|int|null>  $marks
     * @return array{first_ca: float, second_ca: float, exam: float}
     */
    public function legacyFrom(array $marks): array
    {
        $cas = array_values(array_filter($this->parts(), fn ($p) => $p['kind'] === 'ca'));
        $exams = array_filter($this->parts(), fn ($p) => $p['kind'] === 'exam');

        $sum = fn (array $parts) => (float) array_sum(array_map(fn ($p) => (float) ($marks[$p['key']] ?? 0), $parts));

        return [
            'first_ca' => (float) ($marks[$cas[0]['key'] ?? ''] ?? 0),
            'second_ca' => $sum(array_slice($cas, 1)),
            'exam' => $sum($exams),
        ];
    }
}
