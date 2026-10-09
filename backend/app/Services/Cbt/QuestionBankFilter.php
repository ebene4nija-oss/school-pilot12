<?php

namespace App\Services\Cbt;

use App\Models\SectionHeadAssignment;
use App\Models\User;
use App\Support\Sections;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The question bank's filters, shared by the list and the bulk actions, so
 * "select all 340 matching" acts on exactly the 340 the list showed.
 */
class QuestionBankFilter
{
    public const FILTERS = ['subject_id', 'class_id', 'level_group', 'group_id', 'topic', 'difficulty', 'question_type', 'exam_body', 'status', 'search'];

    /** @param  array<string, mixed>  $filters */
    public static function apply(Builder $query, int $schoolId, array $filters): Builder
    {
        // `group_id=null` is a real filter, not a missing one: "show me the
        // standalone questions" is how an author finds what is not yet
        // attached to a passage.
        if (array_key_exists('group_id', $filters)) {
            $groupId = $filters['group_id'];
            $query->when(
                $groupId === null || $groupId === '' || $groupId === 'null',
                fn ($q) => $q->whereNull('group_id'),
                fn ($q) => $q->where('group_id', $groupId)
            );
        }

        // `class_id=none` finds the unsorted questions written before classes.
        if (filled($filters['class_id'] ?? null)) {
            $filters['class_id'] === 'none'
                ? $query->whereNull('class_id')
                : $query->where('class_id', $filters['class_id']);
        }

        if (filled($filters['level_group'] ?? null)) {
            $classIds = DB::table('classes')->where('school_id', $schoolId)->get(['id', 'level_category'])
                ->filter(fn ($c) => Sections::levelGroupOf($c->level_category) === $filters['level_group'])
                ->pluck('id');
            $query->whereIn('class_id', $classIds->all() ?: [0]);
        }

        foreach (['subject_id', 'topic', 'difficulty', 'question_type', 'exam_body', 'status'] as $filter) {
            if (filled($filters[$filter] ?? null)) {
                $query->where($filter, $filters[$filter]);
            }
        }

        if (filled($filters['search'] ?? null)) {
            $query->where('question', 'like', '%'.$filters['search'].'%');
        }

        return $query;
    }

    /**
     * Limit a query to the questions a user may change in bulk: admins any,
     * a head of department those in their department's subjects plus their
     * own, a teacher only their own.
     */
    public static function editableBy(Builder $query, User $user, int $schoolId): Builder
    {
        $role = $user->userProfile?->role;

        if (in_array($role, ['super_admin', 'school_admin'], true)) {
            return $query;
        }

        $departments = SectionHeadAssignment::withoutGlobalScopes()
            ->where('school_id', $schoolId)
            ->where('user_id', $user->id)
            ->where('position', 'hod')
            ->whereNull('ended_at')
            ->pluck('department')
            ->filter()
            ->all();

        return $query->where(function ($q) use ($user, $departments, $schoolId) {
            $q->where('created_by', $user->id);

            if ($departments) {
                $q->orWhereIn('subject_id', DB::table('subjects')->where('school_id', $schoolId)->whereIn('department', $departments)->pluck('id')->all() ?: [0]);
            }
        });
    }
}
