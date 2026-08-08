<?php

namespace App\Console\Commands;

use App\Models\CbtAttempt;
use App\Services\CbtExamService;
use Illuminate\Console\Command;

/**
 * Closes out CBT attempts whose time ran out without a submit.
 *
 * This is not housekeeping — it is load-bearing. An attempt only leaves
 * `in_progress` when something finalises it, and the two paths that normally
 * do (a save, or a resume) both require the candidate to come back. A machine
 * that dies mid-paper, a lab that loses power, or a candidate who simply
 * closes the tab leaves the attempt open forever.
 *
 * That is worse than an untidy row. `CbtExamService::startAttempt()` hands
 * back an existing open attempt rather than creating a new one, so a stuck
 * attempt locks that student out of the exam permanently — and the paper never
 * reaches the teacher's marking queue, so the result silently goes missing.
 *
 * Scheduled every minute in routes/console.php. The sweep is one indexed query
 * and grades nothing unless a deadline has genuinely passed, so the cost of
 * running it often is far below the cost of a candidate stuck at a dead screen.
 */
class ExpireOverdueCbtAttempts extends Command
{
    protected $signature = 'cbt:expire-attempts
                            {--school= : Limit the sweep to a single school id}
                            {--dry-run : Report what would be closed without grading anything}';

    protected $description = 'Submit and grade CBT attempts whose server deadline has passed';

    public function handle(CbtExamService $exams): int
    {
        $schoolId = $this->option('school') ? (int) $this->option('school') : null;

        if ($this->option('dry-run')) {
            $query = CbtAttempt::withoutGlobalScopes()
                ->where('status', 'in_progress')
                ->whereNotNull('server_deadline_at')
                ->where('server_deadline_at', '<', now());

            if ($schoolId) {
                $query->where('school_id', $schoolId);
            }

            $overdue = $query->get(['id', 'exam_id', 'student_id', 'server_deadline_at']);

            $this->info("{$overdue->count()} attempt(s) would be submitted:");
            foreach ($overdue as $attempt) {
                $this->line(sprintf(
                    '  attempt %d (exam %d, student %d) — deadline %s',
                    $attempt->id,
                    $attempt->exam_id,
                    $attempt->student_id,
                    $attempt->server_deadline_at->toDateTimeString()
                ));
            }

            return self::SUCCESS;
        }

        $closed = $exams->expireOverdueAttempts($schoolId);

        // Silent on a normal tick so an every-minute cron does not fill the log
        // with "0 attempts" lines.
        if ($closed > 0) {
            $this->info("Auto-submitted and graded {$closed} overdue CBT attempt(s).");
        }

        return self::SUCCESS;
    }
}
