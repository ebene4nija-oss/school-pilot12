<?php

namespace App\Services;

use App\Models\CaScheme;
use Illuminate\Validation\ValidationException;

/**
 * Turns submitted marks into what a score entry stores, against a school's CA
 * scheme — for single entry, bulk entry and CSV import alike.
 *
 * Two input shapes:
 *
 *  - `components: {ca1: 8, ca2: 9, ca3: 7, exam: 50}` — a mark per part of the
 *    school's scheme. Parts left out keep the mark already entered, so a
 *    teacher can enter the 3rd CA weeks after the first two.
 *  - `first_ca`, `second_ca`, `exam` — the original fields, accepted while the
 *    school's scheme is the classic two-CA shape. A school marking in more
 *    parts is told which parts to send rather than having marks guessed into
 *    the wrong column.
 *
 * Either way the legacy columns are filled too (first CA, other CAs summed,
 * exam), so everything that reads them keeps working.
 */
class ScoreInput
{
    private const LEGACY = ['first_ca', 'second_ca', 'exam'];

    /**
     * @param  array<string, mixed>  $input  the request fields for one score
     * @param  array<string, float|int>|null  $existing  marks already stored for this score
     * @param  string  $prefix  error-key prefix, e.g. `scores.3.` in a bulk request
     * @return array{component_scores: array<string, float>, first_ca: float, second_ca: float, exam: float}
     *
     * @throws ValidationException
     */
    public static function resolve(CaScheme $scheme, array $input, ?array $existing = null, string $prefix = ''): array
    {
        $parts = collect($scheme->parts())->keyBy('key');
        $errors = [];

        if (isset($input['components']) && is_array($input['components'])) {
            $marks = $existing ?? [];

            foreach ($input['components'] as $key => $value) {
                $part = $parts->get($key);

                if (! $part) {
                    $errors[$prefix."components.{$key}"] = ["'{$key}' is not part of this school's marking scheme."];

                    continue;
                }

                if ($value === null || $value === '') {
                    unset($marks[$key]);

                    continue;
                }

                if (! is_numeric($value) || $value < 0 || $value > $part['max']) {
                    $errors[$prefix."components.{$key}"] = ["{$part['name']} is marked out of {$part['max']}."];

                    continue;
                }

                $marks[$key] = (float) $value;
            }
        } elseif (array_intersect(self::LEGACY, array_keys(array_filter($input, fn ($v) => $v !== null && $v !== '')))) {
            if (! $scheme->isClassic()) {
                $names = implode(', ', array_column($scheme->parts(), 'name'));

                throw ValidationException::withMessages([
                    $prefix.'components' => ["This school marks in parts ({$names}). Send a mark for each part as components."],
                ]);
            }

            $marks = [];

            foreach (['first_ca' => 'ca1', 'second_ca' => 'ca2', 'exam' => 'exam'] as $field => $key) {
                $value = $input[$field] ?? 0;
                $part = $parts->get($key);

                if ($value === null || $value === '') {
                    $value = 0;
                }

                if (! is_numeric($value) || $value < 0 || $value > $part['max']) {
                    $label = ['ca1' => 'First CA', 'ca2' => 'Second CA', 'exam' => 'The exam'][$key];
                    $errors[$prefix.$field] = ["{$label} is marked out of {$part['max']} under the '{$scheme->name}' scheme."];

                    continue;
                }

                $marks[$key] = (float) $value;
            }
        } else {
            $marks = $existing ?? [];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        // Stored in the scheme's own order.
        $ordered = [];
        foreach ($parts->keys() as $key) {
            if (array_key_exists($key, $marks)) {
                $ordered[$key] = $marks[$key];
            }
        }

        return ['component_scores' => $ordered] + $scheme->legacyFrom($ordered);
    }
}
