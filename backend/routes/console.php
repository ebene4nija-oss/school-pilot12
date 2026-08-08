<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled tasks
|--------------------------------------------------------------------------
*/

/*
 * Close out CBT attempts whose time has run out.
 *
 * Every minute, because the failure it prevents is a candidate locked out of
 * their own exam: startAttempt() returns an existing open attempt rather than
 * creating a new one, so an attempt left at `in_progress` by a dead lab
 * machine bars that student from re-entering, and the paper never reaches the
 * marking queue. The sweep is one indexed query and does nothing at all unless
 * a deadline has genuinely passed.
 *
 * withoutOverlapping so a slow run during a large sitting cannot stack up and
 * grade the same attempt twice — submitAttempt() is idempotent, but there is
 * no reason to lean on that every minute.
 */
Schedule::command('cbt:expire-attempts')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
