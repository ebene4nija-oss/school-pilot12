<?php

namespace App\Services\Marketing;

/**
 * Lead score from the Landing PRD §12.3: simple enough that a sales rep can
 * work it out by hand and argue with it.
 *
 *   +3 proprietor / director     +2 principal or bursar
 *   +2 band of 201+ students     +2 visited the demo school
 *   +2 viewed pricing            +1 per feature page (max 3)
 *   +3 arrived through /migrate
 *
 * `pages` are the site paths the visitor viewed this session, sent by the
 * site with the form. They are paths, not personal data.
 */
class LeadScorer
{
    private const BIG_BANDS = ['201-500', '501-1000', '1001-3000', '3000+'];

    /** @param  array<int, string>  $pages */
    public static function score(string $role, ?string $band, array $pages, ?string $landingPage): int
    {
        $score = match ($role) {
            'proprietor' => 3,
            'principal', 'bursar' => 2,
            default => 0,
        };

        if ($band !== null && in_array($band, self::BIG_BANDS, true)) {
            $score += 2;
        }

        $paths = array_values(array_unique(array_filter(
            array_merge($pages, [$landingPage]),
            fn ($p) => is_string($p) && $p !== '',
        )));

        if (in_array('demo-school', $paths, true)) {
            $score += 2;
        }

        if (in_array('/pricing', $paths, true)) {
            $score += 2;
        }

        $featurePages = count(array_filter($paths, fn ($p) => str_starts_with($p, '/features/')));
        $score += min(3, $featurePages);

        if (in_array('/migrate', $paths, true)) {
            $score += 3;
        }

        return $score;
    }
}
