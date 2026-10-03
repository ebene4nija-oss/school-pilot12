<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SchoolClass;
use App\Models\SchoolSectionSetting;
use App\Models\SectionHeadAssignment;
use App\Models\User;
use App\Services\Sections\HeadResolver;
use App\Support\Sections;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Sections, heads and approval chains (TASK/SCHOOLPILOT_SECTIONS_DECISIONS_BASELINE).
 *
 * The school admin stands in for the owner here. Everything written is
 * audited, because who heads a level decides who approves its results.
 */
class SectionsController extends Controller
{
    public function __construct(private HeadResolver $heads)
    {
    }

    public function show(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $settings = SchoolSectionSetting::for($schoolId);

        $chains = collect($settings->chains())->map(fn ($steps, $section) => collect($steps)->map(fn ($s) => $s + [
            'label' => Sections::STEP_ROLES[$s['role']] ?? $s['role'],
            'can_disable' => ! collect(Sections::DEFAULT_CHAINS[$section])->firstWhere('role', $s['role'])['required'],
        ])->values());

        return response()->json([
            'secondary_heads_mode' => $settings->secondary_heads_mode,
            'result_workflow_enabled' => (bool) $settings->result_workflow_enabled,
            'scheme_approval_delegates' => User::whereIn('id', (array) $settings->scheme_approval_delegates)->get(['id', 'name']),
            'approval_chains' => $chains,
            'sections' => Sections::SECTIONS,
            'level_groups' => Sections::LEVEL_GROUPS,
            'step_roles' => Sections::STEP_ROLES,
            'heads' => $this->headRows($schoolId),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $data = $request->validate([
            'secondary_heads_mode' => 'sometimes|in:single_principal,separate_heads',
            'result_workflow_enabled' => 'sometimes|boolean',
            'approval_chains' => 'sometimes|array',
            'approval_chains.*' => 'array',
            'approval_chains.*.*.role' => ['required_with:approval_chains', Rule::in(array_keys(Sections::STEP_ROLES))],
            'approval_chains.*.*.enabled' => 'required_with:approval_chains|boolean',
            'scheme_approval_delegates' => 'sometimes|array',
            'scheme_approval_delegates.*' => 'integer',
        ]);

        if (isset($data['approval_chains'])) {
            $problems = [];

            foreach ($data['approval_chains'] as $section => $steps) {
                foreach (Sections::chainProblems($section, $steps) as $p) {
                    $problems[] = (Sections::SECTIONS[$section] ?? $section).": {$p}";
                }
            }

            if ($problems !== []) {
                return response()->json(['message' => $problems[0], 'errors' => ['approval_chains' => $problems]], 422);
            }

            // Stored with the required flag from the baseline, not the client.
            $data['approval_chains'] = collect($data['approval_chains'])->map(fn ($steps, $section) => collect($steps)->values()->map(fn ($s, $i) => [
                'role' => $s['role'],
                'enabled' => (bool) $s['enabled'],
                'required' => Sections::DEFAULT_CHAINS[$section][$i]['required'],
            ])->all())->all() + (array) (SchoolSectionSetting::for($schoolId)->approval_chains ?? []);
        }

        if (isset($data['scheme_approval_delegates'])) {
            $staff = $this->staffIds($schoolId, $data['scheme_approval_delegates']);

            if (count($staff) !== count(array_unique($data['scheme_approval_delegates']))) {
                return response()->json(['message' => 'Scheme approval can be delegated only to staff of this school.'], 422);
            }

            $data['scheme_approval_delegates'] = $staff;
        }

        $settings = SchoolSectionSetting::withoutGlobalScopes()->firstOrNew(['school_id' => $schoolId]);
        $old = $settings->exists ? $settings->toArray() : null;
        $settings->fill($data)->save();
        $this->heads->forget($schoolId);

        $this->audit($request, 'sections.settings_updated', $settings->id, $old, $settings->toArray());

        return $this->show($request);
    }

    public function assignHead(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $data = $request->validate([
            'position' => ['required', Rule::in(SectionHeadAssignment::POSITIONS)],
            'level_group' => ['nullable', Rule::in(array_keys(Sections::LEVEL_GROUPS))],
            'department' => 'nullable|string|max:100',
            'user_id' => 'required|integer',
        ]);

        if ($this->staffIds($schoolId, [$data['user_id']]) === []) {
            return response()->json(['message' => 'Only staff of this school can hold a head position.'], 422);
        }

        if ($data['position'] === 'level_head' && empty($data['level_group'])) {
            return response()->json(['message' => 'Choose the level this head is responsible for.'], 422);
        }

        if ($data['position'] === 'hod' && empty($data['department'])) {
            return response()->json(['message' => 'Choose the department this HOD heads.'], 422);
        }

        if ($data['position'] === 'principal') {
            $data['level_group'] = null;
            $data['department'] = null;
        }

        $duplicate = SectionHeadAssignment::where('school_id', $schoolId)
            ->current()
            ->where('position', $data['position'])
            ->where('user_id', $data['user_id'])
            ->where(fn ($q) => empty($data['level_group']) ? $q->whereNull('level_group') : $q->where('level_group', $data['level_group']))
            ->where(fn ($q) => empty($data['department']) ? $q->whereNull('department') : $q->where('department', $data['department']))
            ->exists();

        if ($duplicate) {
            return response()->json(['message' => 'That person already holds this position.'], 422);
        }

        $assignment = SectionHeadAssignment::create($data + [
            'school_id' => $schoolId,
            'assigned_by' => $request->user()->id,
            'started_at' => now(),
        ]);

        $this->heads->forget($schoolId);
        $this->audit($request, 'sections.head_assigned', $assignment->id, null, $assignment->toArray());

        return response()->json(['message' => 'Head assigned.', 'heads' => $this->headRows($schoolId)], 201);
    }

    /** Ending keeps the row: who held the post when is part of the record. */
    public function endHead(Request $request, int $id): JsonResponse
    {
        $schoolId = $this->schoolId();
        $assignment = SectionHeadAssignment::where('school_id', $schoolId)->current()->findOrFail($id);
        $assignment->update(['ended_at' => now()]);

        $this->heads->forget($schoolId);
        $this->audit($request, 'sections.head_ended', $assignment->id, null, ['ended_at' => now()->toIso8601String()]);

        return response()->json(['message' => 'Assignment ended.', 'heads' => $this->headRows($schoolId)]);
    }

    /** Who acts at each step for a class — what the broadsheet will ask for. */
    public function resolve(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $request->validate(['class_id' => ['required', Rule::exists('classes', 'id')->where('school_id', $schoolId)]]);

        $class = SchoolClass::where('school_id', $schoolId)->findOrFail($request->integer('class_id'));
        $levelGroup = Sections::levelGroupOf($class->level_category);
        $settings = SchoolSectionSetting::for($schoolId);

        $steps = collect(Sections::enabledSteps($settings->approval_chains, Sections::sectionOf($levelGroup)))
            ->map(function ($step) use ($schoolId, $class, $levelGroup) {
                $ids = in_array($step['role'], ['subject_teacher', 'hod'], true)
                    ? []
                    : $this->heads->holders($schoolId, $step['role'], $class->id, $levelGroup);

                return $step + [
                    'holders' => User::whereIn('id', $ids)->pluck('name')->all(),
                    'vacant' => ! in_array($step['role'], ['subject_teacher', 'hod'], true) && $ids === [],
                    'note' => match ($step['role']) {
                        'subject_teacher' => 'Each subject\'s assigned teacher.',
                        'hod' => 'The head of each subject\'s department; skipped where a department has none.',
                        default => $ids === [] ? 'Nobody holds this role, so the school admin acts for it.' : null,
                    },
                ];
            });

        return response()->json([
            'class' => ['id' => $class->id, 'name' => $class->name, 'level_group' => $levelGroup, 'section' => Sections::sectionOf($levelGroup)],
            'steps' => $steps,
        ]);
    }

    private function headRows(int $schoolId)
    {
        return SectionHeadAssignment::where('school_id', $schoolId)
            ->with('user:id,name')
            ->orderByRaw('ended_at IS NOT NULL')
            ->orderBy('position')
            ->get()
            ->map(fn ($h) => [
                'id' => $h->id,
                'position' => $h->position,
                'level_group' => $h->level_group,
                'level_label' => $h->level_group ? Sections::LEVEL_GROUPS[$h->level_group] ?? $h->level_group : null,
                'department' => $h->department,
                'user_id' => $h->user_id,
                'name' => $h->user?->name,
                'started_at' => $h->started_at?->toIso8601String(),
                'ended_at' => $h->ended_at?->toIso8601String(),
                'current' => $h->ended_at === null,
            ]);
    }

    /** @return array<int, int> the ids among these that are staff of this school */
    private function staffIds(int $schoolId, array $ids): array
    {
        return DB::table('user_profiles')
            ->where('school_id', $schoolId)
            ->whereIn('user_id', $ids)
            ->whereIn('role', ['school_admin', 'teacher'])
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function audit(Request $request, string $action, ?int $id, ?array $old, ?array $new): void
    {
        AuditLog::create([
            'school_id' => $this->schoolId(),
            'user_id' => $request->user()->id,
            'action' => $action,
            'auditable_type' => SectionHeadAssignment::class,
            'auditable_id' => $id,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => $request->ip(),
        ]);
    }
}
