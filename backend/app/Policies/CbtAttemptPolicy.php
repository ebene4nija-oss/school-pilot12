<?php

namespace App\Policies;

use App\Models\CbtAttempt;
use App\Models\Student;
use App\Models\User;

/**
 * An attempt is the most sensitive object in the CBT engine: it is one named
 * child's exam paper, mid-flight. Everything here is object-level — school
 * membership on its own never grants access.
 */
class CbtAttemptPolicy
{
    /**
     * Continue sitting: save answers, log integrity events, submit.
     *
     * Candidates only, and only their own attempt. A teacher cannot type into
     * a child's paper — they mark it afterwards via `grade`.
     */
    public function sit(User $user, CbtAttempt $attempt): bool
    {
        $profile = $user->userProfile;

        if (! $profile || $profile->role !== 'student') {
            return false;
        }

        $student = Student::where('user_id', $user->id)->first();

        return $student
            && (int) $attempt->student_id === (int) $student->id
            && (int) $attempt->school_id === (int) $student->school_id;
    }

    /**
     * Read a finished attempt: the candidate, their guardians, and the
     * school's staff.
     */
    public function view(User $user, CbtAttempt $attempt): bool
    {
        $profile = $user->userProfile;

        if (! $profile) {
            return false;
        }

        if ($profile->role !== 'super_admin' && (int) $profile->school_id !== (int) $attempt->school_id) {
            return false;
        }

        if (in_array($profile->role, ['super_admin', 'school_admin', 'teacher'], true)) {
            return true;
        }

        $student = Student::withoutGlobalScopes()->find($attempt->student_id);

        if (! $student) {
            return false;
        }

        return match ($profile->role) {
            'student' => (int) $student->user_id === (int) $user->id,
            'parent' => $student->isGuardedBy($user),
            default => false,
        };
    }

    /** Mark the theory questions a machine could not. Staff only. */
    public function grade(User $user, CbtAttempt $attempt): bool
    {
        $profile = $user->userProfile;

        if (! $profile) {
            return false;
        }

        if ($profile->role !== 'super_admin' && (int) $profile->school_id !== (int) $attempt->school_id) {
            return false;
        }

        return in_array($profile->role, ['super_admin', 'school_admin', 'teacher'], true);
    }
}
