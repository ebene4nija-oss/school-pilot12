<?php

namespace App\Support;

/**
 * The current tenant, resolved once and read everywhere.
 *
 * Replaces `Auth::user()->userProfile->school_id` being evaluated inside the
 * global scope on **every model boot**. That was an N+1 baked into the ORM
 * layer: a page listing 50 students re-resolved the acting user's profile 50
 * times, and it did so through the Auth facade, so it could not be reasoned
 * about or overridden.
 *
 * A singleton also gives non-request contexts somewhere to declare who they
 * are. A queued job has no `Auth::user()`, so before this a job touching
 * tenant-scoped models ran completely unscoped across every school on the
 * platform. Jobs now call `forSchool()` explicitly.
 */
class TenantContext
{
    private ?int $schoolId = null;

    private bool $unscoped = false;

    public function setSchoolId(?int $schoolId): void
    {
        $this->schoolId = $schoolId;
    }

    public function schoolId(): ?int
    {
        return $this->schoolId;
    }

    public function hasSchool(): bool
    {
        return $this->schoolId !== null;
    }

    /**
     * Whether queries should skip tenant filtering entirely.
     *
     * True for a platform operator (super admin) and for the deliberately
     * cross-tenant lookups — the payment webhook resolves a gateway reference
     * before it knows which school the payment belongs to.
     */
    public function isUnscoped(): bool
    {
        return $this->unscoped;
    }

    public function markUnscoped(bool $unscoped = true): void
    {
        $this->unscoped = $unscoped;
    }

    /**
     * Run a callback as a specific school, restoring whatever was set before.
     *
     * The restore matters for queue workers: one long-lived process handles
     * jobs for many schools in sequence, and a leaked school id would scope
     * the next school's job to the previous school's data.
     */
    public function forSchool(?int $schoolId, callable $callback): mixed
    {
        $previousSchool = $this->schoolId;
        $previousUnscoped = $this->unscoped;

        $this->schoolId = $schoolId;
        $this->unscoped = false;

        try {
            return $callback();
        } finally {
            $this->schoolId = $previousSchool;
            $this->unscoped = $previousUnscoped;
        }
    }

    /** Run a callback with tenant filtering off, then restore. */
    public function withoutScoping(callable $callback): mixed
    {
        $previous = $this->unscoped;
        $this->unscoped = true;

        try {
            return $callback();
        } finally {
            $this->unscoped = $previous;
        }
    }

    public function reset(): void
    {
        $this->schoolId = null;
        $this->unscoped = false;
    }
}
