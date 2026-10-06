<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\School;
use App\Services\Ai\AiGateway;
use App\Support\SchoolFeatures;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Module switches and AI features — see SchoolFeatures for who owns which.
 *
 * School side: every signed-in member can read what their school has (the
 * menu uses it); a school admin can switch AI features off and on within what
 * SchoolPilot allows.
 *
 * Operator side: SchoolPilot switches modules on or off per school. Audited as
 * `platform.school.modules_changed`, alongside the AI switch.
 */
class SchoolFeatureController extends Controller
{
    public function __construct(private TenantContext $tenant)
    {
    }

    public function show()
    {
        $school = School::find($this->schoolId());

        if (! $school) {
            return response()->json(['message' => 'Choose a school first.'], 409);
        }

        return response()->json($this->describe($school));
    }

    /** A school admin choosing which AI features the school uses. */
    public function updateAi(Request $request)
    {
        $school = School::find($this->schoolId());

        if (! $school) {
            return response()->json(['message' => 'Choose a school first.'], 409);
        }

        $keys = array_keys(SchoolFeatures::AI_FEATURES);

        $validated = $request->validate([
            'features' => 'required|array',
            'features.*' => 'boolean',
        ]);

        $unknown = array_diff(array_keys($validated['features']), $keys);

        if ($unknown !== []) {
            return response()->json(['message' => 'Unknown AI feature: '.implode(', ', $unknown).'.'], 422);
        }

        $before = SchoolFeatures::aiFeatures($school);
        $after = array_merge($before, array_map('boolval', $validated['features']));

        $school->ai_feature_flags = array_merge((array) ($school->ai_feature_flags ?? []), $after);
        $school->save();

        $this->audit($request, $school, 'school.ai_features.updated', $before, $after);

        return response()->json(['message' => 'AI features saved.'] + $this->describe($school->fresh()));
    }

    /**
     * A school admin choosing which AI provider writes for the school, or
     * null for SchoolPilot's default. Only providers SchoolPilot holds a key
     * for can be chosen; the cap, the ledger and teacher approval of remarks
     * are the same whichever is used.
     */
    public function updateAiProvider(Request $request)
    {
        $school = School::find($this->schoolId());

        if (! $school) {
            return response()->json(['message' => 'Choose a school first.'], 409);
        }

        $validated = $request->validate([
            'provider' => ['present', 'nullable', Rule::in(array_keys(AiGateway::PROVIDERS))],
        ]);

        $provider = $validated['provider'];

        if ($provider !== null && ! in_array($provider, AiGateway::configuredProviders(), true)) {
            return response()->json([
                'message' => AiGateway::label($provider).' is not available yet. Ask SchoolPilot to set it up.',
                'errors' => ['provider' => ['Not available yet.']],
            ], 422);
        }

        $before = ['ai_provider' => $school->ai_provider];
        $school->ai_provider = $provider;
        $school->save();

        $this->audit($request, $school, 'school.ai_provider.updated', $before, ['ai_provider' => $provider]);

        return response()->json(['message' => 'AI provider saved.'] + $this->describe($school->fresh()));
    }

    public function platformShow(int $schoolId)
    {
        return $this->tenant->withoutScoping(function () use ($schoolId) {
            $school = School::query()->tenants()->find($schoolId);

            return $school
                ? response()->json(['school' => ['id' => $school->id, 'name' => $school->name]] + $this->describe($school))
                : response()->json(['message' => 'School not found'], 404);
        });
    }

    /** The operator switching a school's modules. */
    public function platformUpdate(Request $request, int $schoolId)
    {
        $validated = $request->validate([
            'modules' => 'required|array',
            'modules.*' => 'boolean',
        ]);

        $unknown = array_diff(array_keys($validated['modules']), array_keys(SchoolFeatures::MODULES));

        if ($unknown !== []) {
            return response()->json(['message' => 'Unknown module: '.implode(', ', $unknown).'.'], 422);
        }

        return $this->tenant->withoutScoping(function () use ($request, $schoolId, $validated) {
            $school = School::query()->tenants()->find($schoolId);

            if (! $school) {
                return response()->json(['message' => 'School not found'], 404);
            }

            $before = SchoolFeatures::modules($school);
            $after = array_merge($before, array_map('boolval', $validated['modules']));

            $school->forceFill(['module_switches' => json_encode($after)])->save();

            $this->audit($request, $school, 'platform.school.modules_changed', $before, $after);

            return response()->json(['message' => "Modules saved for {$school->name}."] + $this->describe($school->fresh()));
        });
    }

    private function describe(School $school): array
    {
        $aiAvailable = (bool) ($school->ai_enabled ?? true);
        $choices = SchoolFeatures::aiFeatures($school);

        return [
            'modules' => collect(SchoolFeatures::MODULES)->map(fn ($label, $key) => [
                'key' => $key,
                'label' => $label,
                'enabled' => SchoolFeatures::moduleEnabled($school, $key),
            ])->values(),
            'ai' => [
                // SchoolPilot's switch. Off means every feature is off,
                // whatever the school has chosen below.
                'available' => $aiAvailable,
                'features' => collect(SchoolFeatures::AI_FEATURES)->map(fn ($d, $key) => [
                    'key' => $key,
                    'label' => $d['label'],
                    'hint' => $d['hint'],
                    'chosen' => $choices[$key],
                    'active' => $aiAvailable && $choices[$key],
                ])->values(),
                'provider' => $this->providerSummary($school),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function providerSummary(School $school): array
    {
        $configured = AiGateway::configuredProviders();
        $default = AiGateway::defaultProvider();
        $own = ! empty(((array) ($school->ai_api_settings ?? []))['api_key']);

        return [
            'chosen' => $school->ai_provider,
            'in_use' => app(AiGateway::class)->resolve($school->id)['provider'],
            'default' => $default,
            // A school's own key decides the provider; the choice below waits.
            'own_key' => $own,
            'options' => collect(AiGateway::PROVIDERS)->map(fn ($label, $key) => [
                'key' => $key,
                'label' => $label,
                'available' => in_array($key, $configured, true),
            ])->values(),
        ];
    }

    private function audit(Request $request, School $school, string $action, array $before, array $after): void
    {
        $changed = array_keys(array_diff_assoc($after, $before));

        if ($changed === []) {
            return;
        }

        AuditLog::create([
            'school_id' => $school->id,
            'user_id' => $request->user()->id,
            'action' => $action,
            'auditable_type' => School::class,
            'auditable_id' => $school->id,
            'old_values' => array_intersect_key($before, array_flip($changed)),
            'new_values' => array_intersect_key($after, array_flip($changed)),
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);
    }
}
