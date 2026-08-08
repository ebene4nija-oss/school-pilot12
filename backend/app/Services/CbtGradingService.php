<?php

namespace App\Services;

use App\Models\QuestionBankItem;

/**
 * Auto-grading for every objective question type the engine supports.
 *
 * Grading is deliberately server-side and stateless: given a question and a
 * response, it returns marks. The client never computes a score, and never
 * receives the correct answer while an attempt is open.
 *
 * Marking conventions used throughout:
 *  - a blank response scores 0 and never attracts a negative mark; JAMB-style
 *    negative marking punishes a wrong guess, not an honest omission
 *  - partial credit is opt-in per question via answer_schema.partial_credit
 *  - a partially correct answer is never reported as `is_correct`, but does
 *    carry its fraction of the marks
 */
class CbtGradingService
{
    /**
     * @return array{
     *   is_correct: bool|null,
     *   awarded_marks: float,
     *   requires_manual_grading: bool,
     *   feedback: string|null
     * }
     */
    public function grade(QuestionBankItem $question, ?array $response, float $marks, float $negativeMarks = 0.0): array
    {
        if (!$question->isAutoGradable()) {
            return [
                'is_correct' => null,
                'awarded_marks' => 0.0,
                'requires_manual_grading' => true,
                'feedback' => null,
            ];
        }

        if ($this->isBlank($response)) {
            return [
                'is_correct' => false,
                'awarded_marks' => 0.0,
                'requires_manual_grading' => false,
                'feedback' => null,
            ];
        }

        $fraction = $this->correctFraction($question, $response);

        // Full marks, partial marks, or a penalty for an outright wrong answer.
        if ($fraction >= 1.0) {
            $awarded = $marks;
        } elseif ($fraction > 0.0) {
            $awarded = round($marks * $fraction, 2);
        } else {
            $awarded = -1 * abs($negativeMarks);
        }

        return [
            'is_correct' => $fraction >= 1.0,
            'awarded_marks' => round($awarded, 2),
            'requires_manual_grading' => false,
            'feedback' => null,
        ];
    }

    /**
     * Fraction of the question's marks earned, 0.0 to 1.0.
     */
    public function correctFraction(QuestionBankItem $question, array $response): float
    {
        $schema = $question->answer_schema ?? [];

        return match ($question->question_type) {
            'multiple_choice', 'image_choice' => $this->gradeSingleChoice($question, $response),
            'true_false' => $this->gradeTrueFalse($question, $response),
            'multiple_response' => $this->gradeMultipleResponse($question, $response, $schema),
            'fill_blank' => $this->gradeFillBlank($question, $response, $schema),
            'numeric' => $this->gradeNumeric($question, $response, $schema),
            'matching' => $this->gradeMatching($response, $schema),
            'ordering' => $this->gradeOrdering($question, $response, $schema),
            'diagram_label' => $this->gradeDiagramLabel($response, $schema),
            'hotspot' => $this->gradeHotspot($response, $schema),
            default => 0.0,
        };
    }

    private function gradeSingleChoice(QuestionBankItem $question, array $response): float
    {
        $selected = $this->normaliseKey($response['option'] ?? $response['selected_option'] ?? null);
        $expected = $this->normaliseKey($question->correct_answer);

        return ($selected !== '' && $selected === $expected) ? 1.0 : 0.0;
    }

    private function gradeTrueFalse(QuestionBankItem $question, array $response): float
    {
        $selected = $response['value'] ?? $response['option'] ?? null;
        $expected = $question->correct_answer;

        return $this->toBool($selected) === $this->toBool($expected) ? 1.0 : 0.0;
    }

    /**
     * Multi-select. Default is all-or-nothing (WAEC practice). With
     * partial_credit on, the score is (right picks - wrong picks) / right
     * available, floored at 0 — so ticking every box earns nothing.
     */
    private function gradeMultipleResponse(QuestionBankItem $question, array $response, array $schema): float
    {
        $selected = collect($response['options'] ?? [])
            ->map(fn ($option) => $this->normaliseKey($option))
            ->filter()
            ->unique();

        $expected = collect($schema['correct_options'] ?? $this->splitList($question->correct_answer))
            ->map(fn ($option) => $this->normaliseKey($option))
            ->filter()
            ->unique();

        if ($expected->isEmpty()) {
            return 0.0;
        }

        $hits = $selected->intersect($expected)->count();
        $misses = $selected->diff($expected)->count();

        if (empty($schema['partial_credit'])) {
            return ($hits === $expected->count() && $misses === 0) ? 1.0 : 0.0;
        }

        return max(0.0, ($hits - $misses) / $expected->count());
    }

    /**
     * Free-text gap fill. Matched against a list of accepted spellings, not a
     * single string — "photosynthesis" and "photo-synthesis" are the same
     * answer, and a candidate should not lose a mark to a hyphen.
     */
    private function gradeFillBlank(QuestionBankItem $question, array $response, array $schema): float
    {
        $given = (string) ($response['text'] ?? $response['value'] ?? '');
        $accepted = $schema['accepted'] ?? $this->splitList($question->correct_answer);

        if ($accepted === []) {
            return 0.0;
        }

        $caseSensitive = (bool) ($schema['case_sensitive'] ?? false);
        $normalise = function (string $value) use ($caseSensitive, $schema) {
            $value = trim($value);
            $value = preg_replace('/\s+/u', ' ', $value);
            if (!$caseSensitive) {
                $value = mb_strtolower($value);
            }
            if (!empty($schema['ignore_punctuation'])) {
                $value = preg_replace('/[^\p{L}\p{N}\s]/u', '', $value);
            }

            return $value;
        };

        $givenNormalised = $normalise($given);
        if ($givenNormalised === '') {
            return 0.0;
        }

        foreach ($accepted as $candidate) {
            if ($normalise((string) $candidate) === $givenNormalised) {
                return 1.0;
            }
        }

        return 0.0;
    }

    /**
     * Numeric answers with a tolerance band. Essential for physics and
     * chemistry: 9.8 and 9.81 are both g, and 1/3 typed as 0.333 is right.
     */
    private function gradeNumeric(QuestionBankItem $question, array $response, array $schema): float
    {
        $given = $response['value'] ?? $response['text'] ?? null;

        if (!is_numeric($given)) {
            return 0.0;
        }

        $expected = $schema['value'] ?? $question->correct_answer;
        if (!is_numeric($expected)) {
            return 0.0;
        }

        $given = (float) $given;
        $expected = (float) $expected;
        $tolerance = (float) ($schema['tolerance'] ?? 0.0);

        if (($schema['tolerance_type'] ?? 'absolute') === 'percent') {
            $tolerance = abs($expected) * ($tolerance / 100);
        }

        // A unit mismatch is a wrong answer when the question asked for units.
        if (!empty($schema['require_unit'])) {
            $givenUnit = trim((string) ($response['unit'] ?? ''));
            $expectedUnit = trim((string) ($schema['unit'] ?? ''));
            if (mb_strtolower($givenUnit) !== mb_strtolower($expectedUnit)) {
                return 0.0;
            }
        }

        return abs($given - $expected) <= $tolerance + 1e-9 ? 1.0 : 0.0;
    }

    /**
     * Left-column item -> right-column item. Partial credit is the default
     * here; getting three of four pairs right is genuinely three-quarters of
     * the knowledge.
     */
    private function gradeMatching(array $response, array $schema): float
    {
        $expected = $schema['pairs'] ?? [];
        if (!is_array($expected) || $expected === []) {
            return 0.0;
        }

        $given = $response['pairs'] ?? [];
        if (!is_array($given)) {
            return 0.0;
        }

        $hits = 0;
        foreach ($expected as $left => $right) {
            if (isset($given[$left]) && $this->normaliseKey($given[$left]) === $this->normaliseKey($right)) {
                $hits++;
            }
        }

        $partial = $schema['partial_credit'] ?? true;
        if (!$partial) {
            return $hits === count($expected) ? 1.0 : 0.0;
        }

        return $hits / count($expected);
    }

    /**
     * Sequencing. Partial credit counts items sitting in their correct
     * position, which is the standard rubric for "arrange in order".
     */
    private function gradeOrdering(QuestionBankItem $question, array $response, array $schema): float
    {
        $expected = $schema['order'] ?? $this->splitList($question->correct_answer);
        $given = $response['order'] ?? [];

        if ($expected === [] || !is_array($given)) {
            return 0.0;
        }

        $expected = array_values(array_map(fn ($v) => $this->normaliseKey($v), $expected));
        $given = array_values(array_map(fn ($v) => $this->normaliseKey($v), $given));

        if (empty($schema['partial_credit'])) {
            return $given === $expected ? 1.0 : 0.0;
        }

        $hits = 0;
        foreach ($expected as $index => $value) {
            if (($given[$index] ?? null) === $value) {
                $hits++;
            }
        }

        return $hits / count($expected);
    }

    /**
     * Drag-a-label-onto-a-diagram. answer_schema.zones is a list of
     * { id, label } (plus the geometry the client uses to draw drop targets).
     */
    private function gradeDiagramLabel(array $response, array $schema): float
    {
        $zones = $schema['zones'] ?? [];
        if (!is_array($zones) || $zones === []) {
            return 0.0;
        }

        $given = $response['labels'] ?? [];
        if (!is_array($given)) {
            return 0.0;
        }

        $hits = 0;
        foreach ($zones as $zone) {
            $id = $zone['id'] ?? null;
            if ($id === null) {
                continue;
            }

            $accepted = $zone['accepted'] ?? [$zone['label'] ?? null];
            $answer = $this->normaliseKey($given[$id] ?? '');

            foreach (array_filter((array) $accepted) as $candidate) {
                if ($this->normaliseKey($candidate) === $answer && $answer !== '') {
                    $hits++;
                    break;
                }
            }
        }

        $partial = $schema['partial_credit'] ?? true;
        if (!$partial) {
            return $hits === count($zones) ? 1.0 : 0.0;
        }

        return $hits / count($zones);
    }

    /**
     * Click-the-right-part-of-the-image. Coordinates are normalised 0..1 so a
     * response is resolution-independent — a candidate on a 1024x768 lab
     * monitor and one on a phone produce comparable answers.
     */
    private function gradeHotspot(array $response, array $schema): float
    {
        $zones = $schema['zones'] ?? [];
        if (!is_array($zones) || $zones === []) {
            return 0.0;
        }

        $x = $response['x'] ?? null;
        $y = $response['y'] ?? null;

        if (!is_numeric($x) || !is_numeric($y)) {
            return 0.0;
        }

        $x = (float) $x;
        $y = (float) $y;

        foreach ($zones as $zone) {
            if ($this->pointInZone($x, $y, $zone)) {
                return 1.0;
            }
        }

        return 0.0;
    }

    private function pointInZone(float $x, float $y, array $zone): bool
    {
        $shape = $zone['shape'] ?? 'rect';

        if ($shape === 'circle') {
            $cx = (float) ($zone['cx'] ?? $zone['x'] ?? 0);
            $cy = (float) ($zone['cy'] ?? $zone['y'] ?? 0);
            $r = (float) ($zone['r'] ?? 0);

            return (($x - $cx) ** 2 + ($y - $cy) ** 2) <= $r ** 2;
        }

        $left = (float) ($zone['x'] ?? 0);
        $top = (float) ($zone['y'] ?? 0);
        $width = (float) ($zone['w'] ?? $zone['width'] ?? 0);
        $height = (float) ($zone['h'] ?? $zone['height'] ?? 0);

        return $x >= $left && $x <= $left + $width
            && $y >= $top && $y <= $top + $height;
    }

    private function isBlank(?array $response): bool
    {
        if ($response === null || $response === []) {
            return true;
        }

        foreach (['option', 'selected_option', 'text', 'value', 'unit'] as $key) {
            if (isset($response[$key]) && trim((string) $response[$key]) !== '') {
                return false;
            }
        }

        foreach (['options', 'pairs', 'order', 'labels', 'media_asset_ids'] as $key) {
            if (!empty($response[$key])) {
                return false;
            }
        }

        // A hotspot click at the origin is still a click.
        if (isset($response['x'], $response['y'])) {
            return false;
        }

        return true;
    }

    private function normaliseKey($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return mb_strtolower(trim((string) $value));
    }

    private function toBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(mb_strtolower(trim((string) $value)), ['1', 'true', 't', 'yes', 'y'], true);
    }

    /** Split "A,C" or "A|C" or a JSON array into a list. */
    private function splitList($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value === null || trim((string) $value) === '') {
            return [];
        }

        $value = trim((string) $value);

        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        return array_values(array_filter(array_map('trim', preg_split('/[,|;]/', $value))));
    }
}
