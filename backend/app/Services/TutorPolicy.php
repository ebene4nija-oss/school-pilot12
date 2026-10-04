<?php

namespace App\Services;

use App\Exceptions\DomainRefusal;
use App\Models\CbtAttempt;
use App\Models\Student;
use App\Models\TutorSetting;
use App\Services\Lms\LmsService;
use Illuminate\Support\Facades\DB;

/**
 * What the school allows the tutor to do for this student, right now
 * (TASK 15). Checked before any message reaches the AI provider.
 */
class TutorPolicy
{
    public function __construct(private LmsService $lms)
    {
    }

    /** @throws DomainRefusal with a sentence the student can act on */
    public function assertMayAsk(Student $student, ?int $subjectId): void
    {
        $settings = TutorSetting::for((int) $student->school_id);

        $section = $this->lms->sectionOfClass($student->class_id);
        if ($settings->sections !== null && ! in_array($section, $settings->sections, true)) {
            throw new DomainRefusal('Your school has not turned the AI tutor on for your section.');
        }

        if ($settings->subject_ids !== null) {
            if (! $subjectId) {
                throw new DomainRefusal('Choose a subject first: your school allows the tutor for particular subjects.');
            }
            if (! in_array($subjectId, array_map('intval', $settings->subject_ids), true)) {
                throw new DomainRefusal('Your school has not turned the tutor on for this subject.');
            }
        }

        // No help while sitting an exam: the tutor is for learning, not for answers.
        if ($settings->exam_lock && CbtAttempt::where('student_id', $student->id)->where('status', 'in_progress')->exists()) {
            throw new DomainRefusal('The tutor is paused while you are sitting a CBT exam. Finish or submit it first.');
        }

        if ($settings->daily_message_limit !== null && $this->sentToday($student) >= $settings->daily_message_limit) {
            throw new DomainRefusal("You have used today's {$settings->daily_message_limit} tutor messages. Try again tomorrow.");
        }
    }

    public function sentToday(Student $student): int
    {
        return DB::table('tutor_messages')->join('tutor_conversations', 'tutor_conversations.id', '=', 'tutor_messages.conversation_id')
            ->where('tutor_conversations.student_id', $student->id)->where('tutor_messages.role', 'student')
            ->where('tutor_messages.created_at', '>=', now()->startOfDay())->count();
    }
}
