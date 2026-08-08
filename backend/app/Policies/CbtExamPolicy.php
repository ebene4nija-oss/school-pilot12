<?php

namespace App\Policies;

use App\Models\CbtExam;
use App\Models\Student;
use App\Models\User;

/**
 * Who may author, publish and sit a paper.
 *
 * Route middleware answers "is this a teacher?"; this answers "is this a
 * teacher *of this school*, and is that student *in the class this paper was
 * set for*?" — the questions that matter once a real exam is running.
 */
class CbtExamPolicy
{
    /** Staff read: needed to list, inspect and mark a paper. */
    public function view(User $user, CbtExam $exam): bool
    {
        $profile = $user->userProfile;

        if (! $profile || ! $this->sameSchool($profile, $exam->school_id)) {
            return false;
        }

        return in_array($profile->role, ['super_admin', 'school_admin', 'teacher'], true);
    }

    /**
     * Author or alter a paper. A published paper is deliberately narrower —
     * see `CbtController::updateExam`, which restricts which fields may move
     * once candidates can already see it.
     */
    public function manage(User $user, CbtExam $exam): bool
    {
        $profile = $user->userProfile;

        if (! $profile || ! $this->sameSchool($profile, $exam->school_id)) {
            return false;
        }

        if (in_array($profile->role, ['super_admin', 'school_admin'], true)) {
            return true;
        }

        // A teacher edits the papers they set. Not a colleague's.
        return $profile->role === 'teacher' && (int) $exam->created_by === (int) $user->id;
    }

    /**
     * May this candidate sit this paper?
     *
     * Class binding is the check that stops a JSS1 pupil opening the SS3 mock
     * by guessing an exam id. A paper with no class is school-wide by design.
     */
    public function sit(User $user, CbtExam $exam, Student $student): bool
    {
        $profile = $user->userProfile;

        if (! $profile || $profile->role !== 'student') {
            return false;
        }

        if ((int) $student->user_id !== (int) $user->id) {
            return false;
        }

        if ((int) $student->school_id !== (int) $exam->school_id) {
            return false;
        }

        if ($exam->class_id !== null && (int) $student->class_id !== (int) $exam->class_id) {
            return false;
        }

        return true;
    }

    private function sameSchool($profile, $schoolId): bool
    {
        return $profile->role === 'super_admin' || (int) $profile->school_id === (int) $schoolId;
    }
}
