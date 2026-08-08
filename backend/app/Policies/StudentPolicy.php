<?php

namespace App\Policies;

use App\Models\Student;
use App\Models\User;

/**
 * Object-level authorization for a single student's records.
 *
 * Route middleware answers "is this user a parent?"; this policy answers
 * "is this user a parent *of this child*?" — the distinction the review
 * (docs/TECHNICAL-REVIEW-2026-08-06.md §2.1) found missing.
 */
class StudentPolicy
{
    /**
     * View a student's academic, attendance, behaviour and welfare records.
     */
    public function view(User $user, Student $student): bool
    {
        $profile = $user->userProfile;

        if (! $profile) {
            return false;
        }

        // Cross-tenant access is never permitted, including for staff.
        if ($profile->role !== 'super_admin' && $profile->school_id !== $student->school_id) {
            return false;
        }

        return match ($profile->role) {
            // School staff act on the whole roster within their own school.
            'super_admin', 'school_admin', 'teacher' => true,

            // A parent sees only their own children.
            'parent' => $student->isGuardedBy($user),

            // A student sees only their own record.
            'student' => $student->user_id === $user->id,

            default => false,
        };
    }

    /**
     * Write to a student's portfolio / records. Staff only.
     */
    public function update(User $user, Student $student): bool
    {
        $profile = $user->userProfile;

        if (! $profile) {
            return false;
        }

        if ($profile->role !== 'super_admin' && $profile->school_id !== $student->school_id) {
            return false;
        }

        return in_array($profile->role, ['super_admin', 'school_admin', 'teacher'], true);
    }
}
