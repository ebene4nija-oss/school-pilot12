<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CaScheme;
use App\Models\SchoolSectionSetting;
use App\Models\Term;
use App\Models\User;
use App\Services\Sections\HeadResolver;
use App\Support\Sections;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Versioned assessment schemes (decision D1).
 *
 * A section head proposes; the owner, or someone they delegate to, approves.
 * A scheme takes effect from a chosen term and never reaches a term that
 * already has scores, so no past result is ever recalculated. A head's
 * proposal changes nothing until it is approved and its term starts.
 */
class SchemeVersionController extends Controller
{
    public function __construct(private HeadResolver $heads)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $user = $request->user();

        $rows = CaScheme::where('school_id', $schoolId)
            ->with('effectiveTerm.session')
            ->orderByRaw('status IS NULL')
            ->orderByDesc('id')
            ->get()
            ->map(fn (CaScheme $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'version' => $s->version,
                'level_group' => $s->level_group,
                'level_label' => $s->level_group ? (Sections::LEVEL_GROUPS[$s->level_group] ?? $s->level_group) : 'All sections',
                'status' => $s->displayStatus(),
                'is_versioned' => $s->status !== null,
                'components' => $s->parts(),
                'total' => $s->totalWeight(),
                'effective_term' => $s->effectiveTerm ? [
                    'id' => $s->effectiveTerm->id,
                    'name' => $s->effectiveTerm->name,
                    'session' => $s->effectiveTerm->session?->name,
                ] : null,
                'proposed_by' => $s->proposed_by ? User::find($s->proposed_by)?->name : null,
                'proposed_at' => $s->proposed_at?->toIso8601String(),
                'approved_by' => $s->approved_by ? User::find($s->approved_by)?->name : null,
                'approved_at' => $s->approved_at?->toIso8601String(),
                'decision_note' => $s->decision_note,
            ]);

        return response()->json([
            'data' => $rows,
            'can_approve' => $this->canApprove($user, $schoolId),
            'can_propose_for' => $this->proposableLevels($user, $schoolId),
        ]);
    }

    /** Create a draft, or propose straight away with `propose: true`. */
    public function store(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'level_group' => ['nullable', Rule::in(array_keys(Sections::LEVEL_GROUPS))],
            'effective_term_id' => ['required', Rule::exists('terms', 'id')->where('school_id', $schoolId)],
            'components' => 'required|array|min:2|max:10',
            'components.*.name' => 'required|string|max:30',
            'components.*.max' => 'required|integer|min:1|max:100',
            'components.*.kind' => 'required|in:ca,exam',
            'propose' => 'boolean',
        ]);

        if (! $this->mayProposeFor($request->user(), $schoolId, $data['level_group'] ?? null)) {
            return response()->json(['message' => 'You can propose a scheme only for the section you head.'], 403);
        }

        $kinds = array_column($data['components'], 'kind');
        if (! in_array('ca', $kinds, true) || ! in_array('exam', $kinds, true)) {
            return response()->json(['errors' => ['components' => ['Include at least one CA and one exam.']]], 422);
        }

        $derived = CaScheme::fromParts($data['components']);

        if ($derived['first_ca_weight'] + $derived['second_ca_weight'] + $derived['exam_weight'] !== 100) {
            return response()->json(['errors' => ['components' => ['The parts of a versioned scheme must add up to 100.']]], 422);
        }

        if ($problem = $this->termProblem($schoolId, (int) $data['effective_term_id'], $data['level_group'] ?? null)) {
            return response()->json(['message' => $problem], 422);
        }

        $version = (int) CaScheme::where('school_id', $schoolId)
            ->whereNotNull('status')
            ->where(fn ($q) => empty($data['level_group']) ? $q->whereNull('level_group') : $q->where('level_group', $data['level_group']))
            ->max('version') + 1;

        $propose = $request->boolean('propose');

        $scheme = CaScheme::create($derived + [
            'school_id' => $schoolId,
            'name' => $data['name'],
            'is_default' => false,
            'status' => $propose ? 'proposed' : 'draft',
            'version' => $version,
            'level_group' => $data['level_group'] ?? null,
            'effective_term_id' => $data['effective_term_id'],
            'proposed_by' => $request->user()->id,
            'proposed_at' => $propose ? now() : null,
        ]);

        $this->audit($request, $propose ? 'scheme.proposed' : 'scheme.drafted', $scheme);

        return response()->json([
            'message' => $propose ? 'Scheme proposed. It changes nothing until it is approved and its term starts.' : 'Draft saved.',
            'scheme' => $scheme,
        ], 201);
    }

    public function propose(Request $request, int $id): JsonResponse
    {
        $scheme = $this->find($id);

        if ($scheme->status !== 'draft') {
            return response()->json(['message' => 'Only a draft can be proposed.'], 409);
        }

        if (! $this->mayProposeFor($request->user(), $scheme->school_id, $scheme->level_group)) {
            return response()->json(['message' => 'You can propose a scheme only for the section you head.'], 403);
        }

        $scheme->update(['status' => 'proposed', 'proposed_at' => now()]);
        $this->audit($request, 'scheme.proposed', $scheme);

        return response()->json(['message' => 'Scheme proposed for approval.', 'scheme' => $scheme->fresh()]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $request->validate(['note' => 'nullable|string|max:1000']);
        $schoolId = $this->schoolId();
        $scheme = $this->find($id);

        if (! $this->canApprove($request->user(), $schoolId)) {
            return response()->json(['message' => 'Only the school owner, or someone they have delegated to, can approve a scheme.'], 403);
        }

        if ($scheme->status !== 'proposed') {
            return response()->json(['message' => 'Only a proposed scheme can be approved.'], 409);
        }

        // Checked again at approval: scores may have been entered since it was proposed.
        if ($problem = $this->termProblem($schoolId, (int) $scheme->effective_term_id, $scheme->level_group)) {
            return response()->json(['message' => $problem], 409);
        }

        $scheme->update([
            'status' => 'approved',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'decision_note' => $request->input('note'),
        ]);

        $this->audit($request, 'scheme.approved', $scheme);

        return response()->json([
            'message' => 'Approved. It applies from '.($scheme->effectiveTerm?->name ?? 'its term').'; earlier terms keep the scheme they were marked under.',
            'scheme' => $scheme->fresh(),
        ]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $request->validate(['note' => 'required|string|min:3|max:1000']);
        $scheme = $this->find($id);

        if (! $this->canApprove($request->user(), $this->schoolId())) {
            return response()->json(['message' => 'Only the school owner, or someone they have delegated to, can reject a scheme.'], 403);
        }

        if ($scheme->status !== 'proposed') {
            return response()->json(['message' => 'Only a proposed scheme can be rejected.'], 409);
        }

        $scheme->update(['status' => 'rejected', 'approved_by' => $request->user()->id, 'approved_at' => now(), 'decision_note' => $request->input('note')]);
        $this->audit($request, 'scheme.rejected', $scheme);

        return response()->json(['message' => 'Proposal rejected.', 'scheme' => $scheme->fresh()]);
    }

    /** The scheme in force for a term and level, for the score-entry screen. */
    public function forTerm(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $request->validate([
            'term_id' => ['required', Rule::exists('terms', 'id')->where('school_id', $schoolId)],
            'level_group' => ['nullable', Rule::in(array_keys(Sections::LEVEL_GROUPS))],
        ]);

        $scheme = CaScheme::forTerm($schoolId, $request->integer('term_id'), $request->input('level_group'));

        return response()->json([
            'name' => $scheme->name,
            'version' => $scheme->version ?? 1,
            'components' => $scheme->parts(),
            'total' => $scheme->totalWeight(),
        ]);
    }

    /**
     * Why a scheme cannot take effect from this term, or null.
     *
     * The term and every later one must have no scores for the classes the
     * scheme covers — a scheme never lands on marks already entered.
     */
    private function termProblem(int $schoolId, int $termId, ?string $levelGroup): ?string
    {
        $term = Term::where('school_id', $schoolId)->findOrFail($termId);

        $termIds = Term::where('school_id', $schoolId)
            ->where('start_date', '>=', $term->start_date->toDateString())
            ->pluck('id');

        $classIds = DB::table('classes')
            ->where('school_id', $schoolId)
            ->get(['id', 'level_category'])
            ->filter(fn ($c) => $levelGroup === null
                || Sections::levelGroupOf($c->level_category) === $levelGroup
                || ($levelGroup === 'secondary' && Sections::isSecondaryLevel(Sections::levelGroupOf($c->level_category))))
            ->pluck('id');

        $scored = DB::table('score_entries')
            ->join('students', 'students.id', '=', 'score_entries.student_id')
            ->where('score_entries.school_id', $schoolId)
            ->whereIn('score_entries.term_id', $termIds)
            ->whereIn('students.class_id', $classIds)
            ->whereNull('score_entries.deleted_at')
            ->exists();

        return $scored
            ? "{$term->name} (or a later term) already has scores for these classes. Choose a term that has not started marking; past results are never recalculated."
            : null;
    }

    private function canApprove(User $user, int $schoolId): bool
    {
        if ($user->userProfile?->role === 'school_admin') {
            return true;
        }

        return in_array($user->id, array_map('intval', (array) SchoolSectionSetting::for($schoolId)->scheme_approval_delegates), true);
    }

    /** @return array<int, string>|null null = any level */
    private function proposableLevels(User $user, int $schoolId): ?array
    {
        return $this->heads->levelsHeadedBy($user, $schoolId);
    }

    private function mayProposeFor(User $user, int $schoolId, ?string $levelGroup): bool
    {
        $levels = $this->proposableLevels($user, $schoolId);

        if ($levels === null) {
            return true;
        }

        return $levelGroup !== null && (in_array($levelGroup, $levels, true)
            || (in_array('secondary', $levels, true) && Sections::isSecondaryLevel($levelGroup)));
    }

    private function find(int $id): CaScheme
    {
        return CaScheme::where('school_id', $this->schoolId())->whereNotNull('status')->findOrFail($id);
    }

    private function audit(Request $request, string $action, CaScheme $scheme): void
    {
        AuditLog::create([
            'school_id' => $scheme->school_id,
            'user_id' => $request->user()->id,
            'action' => $action,
            'auditable_type' => CaScheme::class,
            'auditable_id' => $scheme->id,
            'new_values' => $scheme->only(['name', 'version', 'level_group', 'effective_term_id', 'status', 'components']),
            'ip_address' => $request->ip(),
        ]);
    }
}
