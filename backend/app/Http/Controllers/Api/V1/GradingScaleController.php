<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CaScheme;
use App\Models\GradingScale;
use App\Services\GradingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * A school's grading scales, per class level.
 *
 * Grades used to be WAEC A1–F9 everywhere, whatever the school printed. A
 * school admin sets its own scale here (or SchoolPilot applies the one sent
 * with a report-card design request). Saving can re-grade one term's scores —
 * the current one, normally — so the broadsheet agrees with the new scale;
 * past terms keep the grades their report cards were issued with.
 */
class GradingScaleController extends Controller
{
    public function index()
    {
        $schoolId = $this->schoolId();

        if ($schoolId === null) {
            return response()->json(['message' => 'Choose a school first.'], 409);
        }

        $saved = GradingScale::where('school_id', $schoolId)->get()->keyBy('level');

        // Which levels the school actually has classes at, so the screen can
        // offer a scale per level that matters and nothing else.
        $levelsInUse = DB::table('classes')->where('school_id', $schoolId)->distinct()->pluck('level_category')
            ->map(fn ($l) => GradingScale::normaliseLevel($l))
            ->filter()
            ->unique()
            ->values();

        $scheme = CaScheme::activeFor($schoolId);

        return response()->json([
            'levels' => collect(GradingScale::LEVELS)->map(fn ($label, $level) => [
                'level' => $level,
                'label' => $label,
                'custom' => $saved->has($level),
                'name' => $saved->get($level)?->name,
                'in_use' => $level === 'default' || $levelsInUse->contains($level),
                // What actually applies: own, else default, else WAEC.
                'bands' => $saved->has($level)
                    ? GradingScale::sorted($saved->get($level)->bands)
                    : GradingScale::bandsFor($schoolId, $level === 'default' ? null : $level),
                'updated_at' => $saved->get($level)?->updated_at?->toIso8601String(),
            ])->values(),
            'waec' => GradingScale::WAEC,
            'ca_scheme' => [
                'name' => $scheme->name,
                'first_ca' => $scheme->first_ca_weight,
                'second_ca' => $scheme->second_ca_weight,
                'exam' => $scheme->exam_weight,
            ],
        ]);
    }

    public function update(Request $request, string $level, GradingService $grading)
    {
        $schoolId = $this->schoolId();

        if ($schoolId === null) {
            return response()->json(['message' => 'Choose a school first.'], 409);
        }

        if (! array_key_exists($level, GradingScale::LEVELS)) {
            return response()->json(['message' => 'Unknown class level.'], 404);
        }

        $validated = $request->validate([
            'name' => 'nullable|string|max:80',
            'bands' => 'required|array|min:1|max:20',
            'bands.*.min' => 'required',
            'bands.*.max' => 'required',
            'bands.*.grade' => 'required|string|max:5',
            'bands.*.remark' => 'nullable|string|max:40',
            'regrade_term_id' => ['nullable', Rule::exists('terms', 'id')->where('school_id', $schoolId)],
        ]);

        if ($problems = GradingScale::problems($validated['bands'])) {
            return response()->json(['message' => $problems[0], 'errors' => ['bands' => $problems]], 422);
        }

        $regraded = $this->save($schoolId, $level, $validated['bands'], $validated['name'] ?? null, $request, $grading, $validated['regrade_term_id'] ?? null);

        return response()->json([
            'message' => 'Grading scale saved.'.($regraded !== null ? " {$regraded} score(s) in the chosen term re-graded." : ''),
            'regraded' => $regraded,
        ]);
    }

    /** Go back to the default (or WAEC) for a level. */
    public function destroy(Request $request, string $level)
    {
        $schoolId = $this->schoolId();

        $scale = GradingScale::where('school_id', $schoolId)->where('level', $level)->first();

        if (! $scale) {
            return response()->json(['message' => 'That level has no scale of its own.'], 404);
        }

        $this->audit($request, $schoolId, 'grading_scale.removed', ['level' => $level, 'bands' => $scale->bands], null);
        $scale->delete();

        return response()->json(['message' => 'Scale removed. This level now uses the school default.']);
    }

    /**
     * Save one level's scale, audit it, and optionally re-grade a term.
     * Shared with the report-card design approval.
     *
     * @return int|null scores re-graded, or null when no term was given
     */
    public function save(int $schoolId, string $level, array $bands, ?string $name, Request $request, GradingService $grading, ?int $regradeTermId): ?int
    {
        $bands = GradingScale::sorted($bands);
        $existing = GradingScale::withoutGlobalScopes()->where('school_id', $schoolId)->where('level', $level)->first();

        GradingScale::withoutGlobalScopes()->updateOrCreate(
            ['school_id' => $schoolId, 'level' => $level],
            ['bands' => $bands, 'name' => $name, 'updated_by' => $request->user()?->id],
        );

        $this->audit($request, $schoolId, 'grading_scale.saved', $existing ? ['level' => $level, 'bands' => $existing->bands] : null, ['level' => $level, 'bands' => $bands]);

        return $regradeTermId ? $grading->regradeTerm($schoolId, (int) $regradeTermId, $level) : null;
    }

    private function audit(Request $request, int $schoolId, string $action, ?array $old, ?array $new): void
    {
        AuditLog::create([
            'school_id' => $schoolId,
            'user_id' => $request->user()->id,
            'action' => $action,
            'auditable_type' => GradingScale::class,
            'auditable_id' => 0,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);
    }
}
