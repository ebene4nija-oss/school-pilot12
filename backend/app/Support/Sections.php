<?php

namespace App\Support;

/**
 * Nursery, Primary and Secondary, and the level groups inside them.
 *
 * A class carries a `level_category` (nursery, primary, junior_secondary,
 * senior_secondary). The column predates this file and defaults to the loose
 * `secondary`, so that value is a level group of its own: it belongs to the
 * Secondary section and resolves its level head to the principal.
 *
 * The approval chains here are the baseline defaults from decision D2. Every
 * one is configurable per school; these are only what a school gets before it
 * changes anything.
 */
class Sections
{
    public const SECTIONS = [
        'nursery' => 'Nursery',
        'primary' => 'Primary',
        'secondary' => 'Secondary',
    ];

    public const LEVEL_GROUPS = [
        'nursery' => 'Nursery',
        'primary' => 'Primary',
        'junior_secondary' => 'Junior Secondary (JSS)',
        'senior_secondary' => 'Senior Secondary (SS)',
        'secondary' => 'Secondary',
    ];

    /** Roles a chain step may name. Each is resolved per class, never per person. */
    public const STEP_ROLES = [
        'subject_teacher' => 'Subject teacher',
        'class_teacher' => 'Class teacher (compile)',
        'hod' => 'Head of department',
        'level_head' => 'Section head',
        'principal' => 'Principal',
    ];

    /**
     * Baseline chains (decision D2). `required: false` marks a step the school
     * may switch off; every step listed here is on by default except the
     * Secondary HOD and principal-release steps, which the baseline calls
     * optional.
     */
    public const DEFAULT_CHAINS = [
        'nursery' => [
            ['role' => 'subject_teacher', 'enabled' => true, 'required' => true],
            ['role' => 'class_teacher', 'enabled' => false, 'required' => false],
            ['role' => 'level_head', 'enabled' => true, 'required' => true],
        ],
        'primary' => [
            ['role' => 'subject_teacher', 'enabled' => true, 'required' => true],
            ['role' => 'class_teacher', 'enabled' => true, 'required' => false],
            ['role' => 'level_head', 'enabled' => true, 'required' => true],
        ],
        'secondary' => [
            ['role' => 'subject_teacher', 'enabled' => true, 'required' => true],
            ['role' => 'class_teacher', 'enabled' => true, 'required' => false],
            ['role' => 'hod', 'enabled' => false, 'required' => false],
            ['role' => 'level_head', 'enabled' => true, 'required' => true],
            ['role' => 'principal', 'enabled' => false, 'required' => false],
        ],
    ];

    /** The level group for a class's `level_category`. */
    public static function levelGroupOf(?string $levelCategory): string
    {
        return isset(self::LEVEL_GROUPS[$levelCategory ?? '']) ? $levelCategory : 'secondary';
    }

    /** The section a level group belongs to. */
    public static function sectionOf(?string $levelGroup): string
    {
        return match ($levelGroup) {
            'nursery' => 'nursery',
            'primary' => 'primary',
            default => 'secondary',
        };
    }

    public static function isSecondaryLevel(?string $levelGroup): bool
    {
        return self::sectionOf($levelGroup) === 'secondary';
    }

    /**
     * Whether a subject tagged with these level groups is taught at a level.
     *
     * No tags means every level. The loose `secondary` matches either side of
     * it: a subject tagged Secondary is taught in JSS and SS, and a class
     * still marked plain Secondary takes subjects tagged JSS or SS.
     *
     * @param  array<int, string>|null  $subjectLevels
     */
    public static function taughtAt(?array $subjectLevels, string $levelGroup): bool
    {
        if (empty($subjectLevels) || in_array($levelGroup, $subjectLevels, true)) {
            return true;
        }

        if ($levelGroup === 'secondary') {
            return (bool) array_intersect($subjectLevels, ['junior_secondary', 'senior_secondary']);
        }

        return in_array('secondary', $subjectLevels, true) && self::isSecondaryLevel($levelGroup);
    }

    /**
     * Problems with a submitted chain, as sentences; empty when valid.
     *
     * The first step is always the subject teacher, since every other step
     * reviews what they entered, and a required step cannot be switched off.
     *
     * @param  array<int, array{role?: string, enabled?: bool}>  $steps
     * @return array<int, string>
     */
    public static function chainProblems(string $section, array $steps): array
    {
        $defaults = self::DEFAULT_CHAINS[$section] ?? null;

        if ($defaults === null) {
            return ["{$section} is not a section."];
        }

        $roles = array_map(fn ($s) => (string) ($s['role'] ?? ''), $steps);

        if ($roles !== array_column($defaults, 'role')) {
            return ['List the steps in the baseline order: '.implode(', ', array_map(fn ($r) => self::STEP_ROLES[$r], array_column($defaults, 'role'))).'.'];
        }

        $problems = [];

        foreach ($defaults as $i => $default) {
            if ($default['required'] && empty($steps[$i]['enabled'])) {
                $problems[] = self::STEP_ROLES[$default['role']].' cannot be switched off.';
            }
        }

        return $problems;
    }

    /**
     * The enabled steps for a section, from the school's stored chains.
     *
     * @param  array<string, mixed>|null  $stored
     * @return array<int, array{role: string, label: string}>
     */
    public static function enabledSteps(?array $stored, string $section): array
    {
        $chain = $stored[$section] ?? self::DEFAULT_CHAINS[$section];

        return array_values(array_map(
            fn ($s) => ['role' => $s['role'], 'label' => self::STEP_ROLES[$s['role']] ?? $s['role']],
            array_filter($chain, fn ($s) => ! empty($s['enabled']))
        ));
    }
}
