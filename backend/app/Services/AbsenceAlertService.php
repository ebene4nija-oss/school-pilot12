<?php

namespace App\Services;

use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Telling a parent their child was marked absent today.
 *
 * Sent only when the school has switched alerts on and the parent has opted in
 * for that child. At most once per child, per parent, per day: a teacher who
 * resubmits the register, or marks absent then corrects to late, does not send
 * a second message. Only today's register alerts; back-filling last week's
 * attendance must not text a parent about a day long gone.
 *
 * Push always (free); SMS too only if the school has allowed it, since the
 * school pays per message.
 */
class AbsenceAlertService
{
    public function __construct(private NotificationService $notifications)
    {
    }

    /**
     * @param  array<int,int>  $studentIds  students marked absent
     * @return int  alerts sent
     */
    public function send(int $schoolId, array $studentIds, string $date): int
    {
        if ($studentIds === [] || $date !== now()->toDateString()) {
            return 0;
        }

        $school = School::query()->find($schoolId);

        if (! $school || ! $school->absence_alerts_enabled) {
            return 0;
        }

        $channels = $school->absence_alerts_sms ? ['push', 'sms'] : ['push'];

        // Every opted-in parent of every absent child, in one query.
        $rows = DB::table('student_guardian')
            ->join('guardians', 'guardians.id', '=', 'student_guardian.guardian_id')
            ->join('students', 'students.id', '=', 'student_guardian.student_id')
            ->where('students.school_id', $schoolId)
            ->whereIn('student_guardian.student_id', $studentIds)
            ->where('student_guardian.absence_alerts', true)
            ->whereNotNull('guardians.user_id')
            ->get(['student_guardian.student_id', 'guardians.user_id']);

        $sent = 0;

        foreach ($rows as $row) {
            // Claim the slot first: the unique key is what makes a resubmitted
            // register harmless, even when two submissions race.
            try {
                $alertId = DB::table('absence_alerts')->insertGetId([
                    'school_id' => $schoolId,
                    'student_id' => $row->student_id,
                    'guardian_user_id' => $row->user_id,
                    'date' => $date,
                    'outcome' => 'failed',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                continue;
            }

            $guardian = User::with('userProfile')->find($row->user_id);
            $student = Student::withoutGlobalScopes()->with('user:id,name')->find($row->student_id);

            if (! $guardian || ! $student) {
                continue;
            }

            $results = $this->notifications->notify($guardian, $this->message($student, $school), $channels, [
                'title' => 'Absence',
                'category' => 'absence_alert',
                'student_id' => $student->id,
            ]);

            $delivered = collect($results)->filter(fn ($r) => ($r['status'] ?? null) === 'sent')->keys();

            DB::table('absence_alerts')->where('id', $alertId)->update([
                'channels' => $delivered->implode(',') ?: null,
                'outcome' => $delivered->isNotEmpty() ? 'sent' : 'failed',
                'updated_at' => now(),
            ]);

            if ($delivered->isNotEmpty()) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Short enough for one SMS segment, and no more about the child than the
     * parent already knows: a first name and the school.
     */
    private function message(Student $student, School $school): string
    {
        $firstName = strtok((string) $student->user?->name, ' ') ?: 'Your child';

        return "{$firstName} was marked absent at {$school->name} today. If this is unexpected, please contact the school.";
    }
}
