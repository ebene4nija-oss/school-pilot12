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

/*
 * Applications that never became students lose their personal details once
 * the school's retention period has passed (sections baseline D4, NDPA doc
 * §12). Expired offers are closed on the same pass. The reference, section
 * and outcome are kept so admission statistics survive.
 */
Artisan::command('admissions:purge', function () {
    $count = app(\App\Services\AdmissionService::class)->purgeExpired();
    $this->info("Removed personal details from {$count} closed application(s).");
})->purpose('Remove personal details from unaccepted applications past retention');

Schedule::command('admissions:purge')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->runInBackground();

/*
 * LMS reminders (TASK 14 §11): a virtual class about to start, and homework
 * due tomorrow that has not been handed in. Each reminder is sent once (the
 * service dedupes), so the run frequency only sets how early it arrives.
 */
Artisan::command('lms:remind', function () {
    $sent = app(\App\Services\Lms\LmsService::class)->sendReminders();
    $this->info("Reminded {$sent['classes']} class(es) and {$sent['deadlines']} student(s) with work due.");
})->purpose('Send virtual-class and homework-deadline reminders');

Schedule::command('lms:remind')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

/*
 * Library (doc §9.2): lapse uncollected reservation holds, remind readers of
 * books due tomorrow, and send one overdue notice per loan.
 */
Artisan::command('library:daily', function () {
    $r = app(\App\Services\Library\LibraryService::class)->dailyRun();
    $this->info("Expired {$r['expired']} hold(s); {$r['due']} due reminder(s); {$r['overdue']} overdue notice(s).");
})->purpose('Library holds, due reminders and overdue notices');

Schedule::command('library:daily')
    ->dailyAt('07:00')
    ->withoutOverlapping();
