<?php

namespace App\Support;

use App\Models\School;

/**
 * What a school can use: optional modules, and AI features.
 *
 * Two owners, two layers:
 *
 *  - SchoolPilot (the operator) decides what a school has: each optional
 *    module, and AI as a whole (`schools.ai_enabled`). A school cannot turn
 *    on what the operator has turned off.
 *  - The school decides, within that, which AI features it wants
 *    (`schools.ai_feature_flags`) — a school may not want students using the
 *    tutor, or may want report remarks written by hand.
 *
 * Missing keys mean on, so a school that has never been configured keeps
 * everything, and a feature added later starts on for everyone.
 */
class SchoolFeatures
{
    /** Optional modules the operator can switch off, with their labels. */
    public const MODULES = [
        'cbt' => 'CBT exams',
        'live_classes' => 'Virtual classes',
        'transport' => 'Transport',
        'hostel' => 'Hostel',
        'library' => 'Library',
        'clinic' => 'Clinic log',
        'inventory' => 'School store',
        'result_pins' => 'Result PINs (paid result checker)',
        'report_card_design' => 'Custom report-card design',
        'academic_history' => 'Transcripts & transfers',
        'developmental' => 'Early-years ratings',
    ];

    /** How the operator's panel groups the modules. */
    public const MODULE_GROUPS = [
        'cbt' => 'Assessment & results',
        'result_pins' => 'Assessment & results',
        'report_card_design' => 'Assessment & results',
        'academic_history' => 'Assessment & results',
        'developmental' => 'Assessment & results',
        'live_classes' => 'Teaching',
        'hostel' => 'Boarding & campus',
        'inventory' => 'Boarding & campus',
        'transport' => 'Boarding & campus',
        'library' => 'Boarding & campus',
        'clinic' => 'Boarding & campus',
    ];

    /**
     * The API paths each module owns, relative to /api/v1. Enforced by
     * EnforceSchoolModules, so switching a module off closes its endpoints
     * rather than just hiding a menu link.
     */
    public const MODULE_PATHS = [
        'cbt' => '#^cbt(/|$)#',
        'live_classes' => '#^live-classes(/|$)#',
        'transport' => '#^transport(/|$)#',
        // Includes the family and student hostel views.
        'hostel' => '#^(parent/|me/)?hostel(/|$)#',
        'library' => '#^library(/|$)#',
        'clinic' => '#^health/clinic#',
        // The school also has its own switch, off until it opts in (InventorySetting).
        'inventory' => '#^(parent/)?inventory(/|$)#',
        // PIN sales and management only. Families still open report cards
        // through result-checker/{student}/{term}: with this off they open
        // free, as for a school that never charged (ResultCheckerController).
        'result_pins' => '#^(result-pins|result-checker/(purchase|redeem|my-pins))(/|$)#',
        // Requests to SchoolPilot for a bespoke design. The school's current
        // template and its grading stay in use.
        'report_card_design' => '#^report-cards/design-requests(/|$)#',
        'academic_history' => '#^((parent/children/\d+/|students/\d+/|me/)?academic-history|transfers|parent/transfers|parent/children/\d+/transfer-request)(/|$)#',
        'developmental' => '#^developmental(/|$)#',
    ];

    /** AI features a school can switch off for itself, with what each does. */
    public const AI_FEATURES = [
        'report_comments' => ['label' => 'Report-card remark drafts', 'hint' => 'Teachers get a suggested remark to approve or edit. Nothing reaches a report card unreviewed.'],
        'lesson_plans' => ['label' => 'Lesson plans', 'hint' => 'Teachers generate a lesson-plan draft from a topic.'],
        'exam_generation' => ['label' => 'Question and worksheet drafts', 'hint' => 'Teachers generate practice questions and worksheets.'],
        'homework_ideas' => ['label' => 'Homework ideas', 'hint' => 'Teachers get homework suggestions for a topic.'],
        'translation' => ['label' => 'Translation', 'hint' => 'Translate notices and notes for parents.'],
        'performance_summaries' => ['label' => 'Performance summaries', 'hint' => 'A plain-language summary of a student’s results for staff.'],
        'tutor' => ['label' => 'Student tutor chat', 'hint' => 'Students can ask the AI tutor questions about their subjects.'],
    ];

    /** @return array<string, bool> */
    public static function modules(?School $school): array
    {
        $set = self::decode($school?->getAttribute('module_switches'));

        return collect(self::MODULES)->mapWithKeys(fn ($label, $key) => [$key => (bool) ($set[$key] ?? true)])->all();
    }

    public static function moduleEnabled(?School $school, string $module): bool
    {
        return self::modules($school)[$module] ?? true;
    }

    /**
     * Whether families need a PIN to open a report card: the school has
     * switched the checker on, and SchoolPilot hasn't switched PINs off.
     */
    public static function resultPinsRequired(?School $school): bool
    {
        return (bool) ($school?->result_checker_enabled ?? false) && self::moduleEnabled($school, 'result_pins');
    }

    /** The module that owns a /api/v1-relative path, or null. */
    public static function moduleForPath(string $path): ?string
    {
        foreach (self::MODULE_PATHS as $module => $pattern) {
            if (preg_match($pattern, $path)) {
                return $module;
            }
        }

        return null;
    }

    /**
     * The stored switch map. Decoded here rather than by a model cast so the
     * column works whether or not School declares one.
     *
     * @return array<string, mixed>
     */
    public static function decode(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        return is_array($raw) ? $raw : [];
    }

    /** @return array<string, bool> the school's own choices, missing = on */
    public static function aiFeatures(?School $school): array
    {
        $flags = (array) ($school?->ai_feature_flags ?? []);

        return collect(self::AI_FEATURES)->mapWithKeys(fn ($d, $key) => [$key => (bool) ($flags[$key] ?? true)])->all();
    }
}
