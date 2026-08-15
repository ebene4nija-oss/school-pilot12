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

/*
 * Delete ID card print-run PDFs once their download window has passed.
 *
 * Daily and early, because the thing being deleted is a sheet of children's
 * faces and names sitting on disk with no remaining purpose (doc §12). Nothing
 * depends on this having run — the download endpoint checks the expiry itself,
 * so a missed night leaves the file unreachable rather than exposed — which is
 * why it is a quiet 02:30 job and not a minute-by-minute one.
 */
Schedule::command('id-cards:sweep')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->runInBackground();

/*
 * Delete school data archives past their download window.
 *
 * Same reasoning as the ID-card sweep and the same quiet hour: each archive is
 * a complete copy of a school's records, and the download endpoint already
 * refuses an expired one, so a missed night leaves a file unreachable rather
 * than exposed.
 */
Schedule::command('exports:sweep')
    ->dailyAt('02:45')
    ->withoutOverlapping()
    ->runInBackground();
