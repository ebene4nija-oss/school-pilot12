<?php

namespace App\Policies;

use App\Models\Student;
use App\Models\User;
use App\Support\TeacherScope;

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
            'super_admin', 'school_admin' => true,

            /*
             * A teacher sees the students they teach — their form class, or a
             * class they teach a subject in — unless the school admin has
             * granted them the whole school (an exams officer, a head of
             * section). It used to be every child in the school.
             */
            'teacher' => TeacherScope::seesWholeSchool($user) || TeacherScope::teaches($user, $student),

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

        return match ($profile->role) {
            'super_admin', 'school_admin' => true,
            'teacher' => TeacherScope::seesWholeSchool($user) || TeacherScope::teaches($user, $student),
            default => false,
        };
    }

    /**
     * Changing a student's record (name, class, date of birth, contacts,
     * health notes). School admins only: a teacher who teaches a child may
     * read their record but not rewrite it.
     */
    public function editRecord(User $user, Student $student): bool
    {
        $profile = $user->userProfile;

        return $profile !== null
            && in_array($profile->role, ['super_admin', 'school_admin'], true)
            && ($profile->role === 'super_admin' || $profile->school_id === $student->school_id);
    }

    /**
     * Taking a student's passport photo: an admin, or the form teacher of the
     * student's class (and arm, where the assignment names one) this session.
     */
    public function updatePhoto(User $user, Student $student): bool
    {
        if ($this->editRecord($user, $student)) {
            return true;
        }

        $profile = $user->userProfile;
        if ($profile?->role !== 'teacher' || $profile->school_id !== $student->school_id || ! $student->class_id) {
            return false;
        }

        $session = \App\Models\AcademicSession::where('school_id', $student->school_id)->where('is_current', true)->value('id');

        return \App\Models\ClassTeacherAssignment::where('school_id', $student->school_id)
            ->when($session, fn ($q) => $q->where('session_id', $session))
            ->where('class_id', $student->class_id)
            ->where('form_teacher_id', $user->id)
            ->where(fn ($q) => $q->whereNull('arm_id')->orWhere('arm_id', $student->arm_id))
            ->exists();
    }

    /**
     * A student's fee records — statement, invoices, receipts — and nothing
     * else of theirs. Whoever may view the student may view these, and so may
     * the bursar, who works the fee book without seeing results or health
     * records.
     */
    public function viewFinance(User $user, Student $student): bool
    {
        $profile = $user->userProfile;

        if ($profile?->role === 'bursar') {
            return $profile->school_id === $student->school_id;
        }

        return $this->view($user, $student);
    }
}
