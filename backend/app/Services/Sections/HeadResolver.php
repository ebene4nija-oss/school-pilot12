<?php

namespace App\Services\Sections;

use App\Models\SchoolSectionSetting;
use App\Models\SectionHeadAssignment;
use App\Models\User;
use App\Support\Sections;
use App\Support\TeacherScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who holds a chain role for a class (decision D2: steps are roles, not people).
 *
 * "Level head" is the interesting one. Under option A (one principal) every
 * secondary level resolves to the principal. Under option B a JSS or SS head
 * holds their own level and the principal is only the fallback. Switching is a
 * change of `secondary_heads_mode` plus head rows — no data moves (D3).
 *
 * A role nobody holds falls to the school admin, so a school that has not
 * named a principal yet can still finish a term. Where somebody does hold the
 * role, only they may act: the admin cannot jump the queue.
 */
class HeadResolver
{
    /** @var array<string, Collection> per-request memo of current head rows */
    private array $heads = [];

    /**
     * User ids that hold a role for a class.
     *
     * @return array<int, int> empty when the role is vacant
     */
    public function holders(int $schoolId, string $role, int $classId, ?string $levelGroup, ?int $subjectId = null, ?string $department = null): array
    {
        return match ($role) {
            'subject_teacher' => $this->subjectTeachers($schoolId, $classId, $subjectId),
            'class_teacher' => $this->classTeachers($schoolId, $classId),
            'hod' => $this->hods($schoolId, $levelGroup, $department),
            'level_head' => $this->levelHeads($schoolId, $levelGroup),
            'principal' => $this->principals($schoolId),
            default => [],
        };
    }

    /**
     * Whether a user may act for a role on a class.
     *
     * School admins act only for a vacant role. Every other user must hold it.
     */
    public function canAct(User $user, int $schoolId, string $role, int $classId, ?string $levelGroup, ?int $subjectId = null, ?string $department = null): bool
    {
        $holders = $this->holders($schoolId, $role, $classId, $levelGroup, $subjectId, $department);

        if (in_array($user->id, $holders, true)) {
            return true;
        }

        return $holders === [] && $user->userProfile?->role === 'school_admin';
    }

    /**
     * The level groups a user heads. A principal heads all of them; a JSS or
     * SS head is confined to their own level unless granted more (D3).
     *
     * @return array<int, string>|null null = every level
     */
    public function levelsHeadedBy(User $user, int $schoolId): ?array
    {
        $rows = $this->currentHeads($schoolId)->where('user_id', $user->id);

        if ($rows->contains('position', 'principal') || $user->userProfile?->role === 'school_admin') {
            return null;
        }

        return $rows->where('position', 'level_head')->pluck('level_group')->filter()->values()->all();
    }

    private function subjectTeachers(int $schoolId, int $classId, ?int $subjectId): array
    {
        if ($subjectId === null) {
            return [];
        }

        // Assigned subject teachers, and class teachers where the section
        // is taught by class teachers.
        return app(\App\Services\Academics\TeachingAssignments::class)->teachersFor($schoolId, $classId, null, $subjectId);
    }

    private function classTeachers(int $schoolId, int $classId): array
    {
        $sessionId = TeacherScope::currentSessionId($schoolId);

        if ($sessionId === null) {
            return [];
        }

        return DB::table('class_teacher_assignments')
            ->where('school_id', $schoolId)
            ->where('session_id', $sessionId)
            ->where('class_id', $classId)
            ->get(['form_teacher_id', 'assistant_teacher_id'])
            ->flatMap(fn ($r) => [$r->form_teacher_id, $r->assistant_teacher_id])
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function hods(int $schoolId, ?string $levelGroup, ?string $department): array
    {
        if ($department === null || $department === '') {
            return [];
        }

        return $this->currentHeads($schoolId)
            ->where('position', 'hod')
            ->filter(fn ($h) => strcasecmp((string) $h->department, $department) === 0)
            ->filter(fn ($h) => $h->level_group === null || $h->level_group === $levelGroup
                || ($h->level_group === 'secondary' && Sections::isSecondaryLevel($levelGroup)))
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    private function levelHeads(int $schoolId, ?string $levelGroup): array
    {
        $settings = SchoolSectionSetting::for($schoolId);
        $heads = $this->currentHeads($schoolId)->where('position', 'level_head');

        if (Sections::isSecondaryLevel($levelGroup)) {
            if ($settings->separateSecondaryHeads()) {
                $own = $heads->where('level_group', $levelGroup)->pluck('user_id');

                // A head of the whole secondary section covers both levels.
                if ($own->isEmpty()) {
                    $own = $heads->where('level_group', 'secondary')->pluck('user_id');
                }

                if ($own->isNotEmpty()) {
                    return $own->map(fn ($id) => (int) $id)->values()->all();
                }
            }

            // Option A, or a vacant level under option B: the principal.
            return $this->principals($schoolId);
        }

        $own = $heads->where('level_group', $levelGroup)->pluck('user_id');

        return $own->isNotEmpty()
            ? $own->map(fn ($id) => (int) $id)->values()->all()
            : $this->principals($schoolId);
    }

    private function principals(int $schoolId): array
    {
        return $this->currentHeads($schoolId)
            ->where('position', 'principal')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    private function currentHeads(int $schoolId): Collection
    {
        return $this->heads[$schoolId] ??= SectionHeadAssignment::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->whereNull('ended_at')
            ->get(['user_id', 'position', 'level_group', 'department']);
    }

    /** Forget memoised heads after an assignment changes. */
    public function forget(?int $schoolId = null): void
    {
        if ($schoolId === null) {
            $this->heads = [];
        } else {
            unset($this->heads[$schoolId]);
        }
    }
}
