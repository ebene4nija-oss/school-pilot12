<?php

namespace App\Support;

use App\Models\GradingScale;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The brief a school fills in when asking SchoolPilot to design its report
 * card: what the card must show, how marks are split, what the school grades
 * on. Structured rather than a free-text box, so the designer gets the same
 * questions answered for every school and nothing is guessed.
 */
class ReportCardBrief
{
    /** Optional parts of a report card, and how each is described to a school. */
    public const SECTIONS = [
        'passport_photo' => 'Student passport photo',
        'position_in_class' => 'Position in class (e.g. 3rd of 42)',
        'subject_position' => 'Position in each subject',
        'class_average' => 'Class average per subject',
        'highest_lowest' => 'Highest and lowest score per subject',
        'attendance' => 'Attendance (days present / absent)',
        'affective_traits' => 'Affective traits (punctuality, neatness, …)',
        'psychomotor_skills' => 'Psychomotor skills (handwriting, sports, …)',
        'class_teacher_remark' => "Class teacher's remark",
        'principal_remark' => "Principal's / head teacher's remark",
        'grading_key' => 'Grading key printed on the card',
        'next_term_date' => 'Next term begins',
        'next_term_fees' => 'Next term fees',
        'signatures' => 'Signatures / school stamp',
        'verification_qr' => 'Verification QR code (always included)',
    ];

    public const PAPER = ['A4', 'A5', 'LETTER', 'LEGAL'];

    /**
     * Validate and normalise a brief. Throws ValidationException.
     *
     * @return array<string, mixed>
     */
    public static function validate(array $input): array
    {
        $data = Validator::make($input, [
            'contact_name' => 'required|string|max:120',
            'contact_phone' => ['required', 'string', 'max:20', 'regex:/^\+?[\d\s()-]{7,20}$/'],
            'paper_size' => 'nullable|in:'.implode(',', self::PAPER),
            'orientation' => 'nullable|in:portrait,landscape',
            'sections' => 'required|array',
            'sections.*' => 'boolean',
            'ca_components' => 'required|array|min:1|max:8',
            'ca_components.*.name' => 'required|string|max:40',
            'ca_components.*.max' => 'required|integer|min:1|max:100',
            'affective_traits' => 'nullable|array|max:20',
            'affective_traits.*' => 'string|max:40',
            'psychomotor_skills' => 'nullable|array|max:20',
            'psychomotor_skills.*' => 'string|max:40',
            'trait_rating_scale' => 'nullable|string|max:120',
            'colours' => 'nullable|string|max:120',
            'motto' => 'nullable|string|max:160',
            'levels_covered' => 'nullable|array',
            'levels_covered.*' => 'in:'.implode(',', array_keys(GradingScale::LEVELS)),
            'notes' => 'nullable|string|max:3000',
        ], [
            'contact_phone.regex' => 'Enter a phone number such as 0803 123 4567.',
            'ca_components.required' => 'List how marks are split between CAs and the exam.',
        ])->validate();

        $unknown = array_diff(array_keys($data['sections']), array_keys(self::SECTIONS));

        if ($unknown !== []) {
            throw ValidationException::withMessages(['sections' => 'Unknown section: '.implode(', ', $unknown).'.']);
        }

        $sum = array_sum(array_column($data['ca_components'], 'max'));

        if ($sum !== 100) {
            throw ValidationException::withMessages([
                'ca_components' => "The CA and exam marks add up to {$sum}; they should add up to 100.",
            ]);
        }

        // Every section key present, missing ones false; the QR is not optional.
        $data['sections'] = array_merge(
            array_fill_keys(array_keys(self::SECTIONS), false),
            array_map('boolval', $data['sections']),
            ['verification_qr' => true],
        );

        return $data;
    }

    /**
     * Validate the grading scales sent with a brief: `{ level: [bands] }`.
     *
     * @return array<string, array>|null
     */
    public static function validateScales(mixed $scales): ?array
    {
        if ($scales === null || $scales === [] || $scales === '') {
            return null;
        }

        if (! is_array($scales)) {
            throw ValidationException::withMessages(['grading_scales' => 'Grading scales must be a list per class level.']);
        }

        $clean = [];

        foreach ($scales as $level => $bands) {
            if (! array_key_exists($level, GradingScale::LEVELS)) {
                throw ValidationException::withMessages(['grading_scales' => "Unknown class level: {$level}."]);
            }

            if (! is_array($bands) || ($problems = GradingScale::problems($bands))) {
                $label = GradingScale::LEVELS[$level];
                throw ValidationException::withMessages([
                    'grading_scales' => "{$label}: ".($problems[0] ?? 'the scale is not a list of grades.'),
                ]);
            }

            $clean[$level] = GradingScale::sorted($bands);
        }

        return $clean;
    }
}
