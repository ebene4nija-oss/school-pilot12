<?php

namespace App\Support;

use App\Models\School;
use Illuminate\Support\Carbon;

/**
 * A staff member's working day, in the school's own clock.
 *
 * The app runs on UTC; a Nigerian school runs on WAT (UTC+1, no daylight
 * saving). "Late" and "which day" are both answered in WAT, otherwise a
 * 7:20 clock-in reads as 6:20 and a 00:30 one lands on yesterday.
 */
class StaffDay
{
    public const TIMEZONE = 'Africa/Lagos';

    public const DEFAULT_RESUME = '07:30';

    public static function now(): Carbon
    {
        return now(self::TIMEZONE);
    }

    /** "07:30" — the school's resume time, without seconds. */
    public static function resumeTime(School $school): string
    {
        return substr((string) ($school->staff_resume_time ?: self::DEFAULT_RESUME), 0, 5);
    }

    public static function isLate(School $school, Carbon $at): bool
    {
        $local = $at->copy()->setTimezone(self::TIMEZONE);

        return $local->format('H:i') > self::resumeTime($school);
    }
}
