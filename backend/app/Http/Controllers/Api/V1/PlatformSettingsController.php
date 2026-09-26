<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AiUsageDaily;
use App\Models\AuditLog;
use App\Models\PlatformSetting;
use App\Models\School;
use App\Support\PlatformSettings;
use App\Support\TenantContext;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Platform settings and AI service configuration (doc §5: "platform-wide
 * settings, AI service configuration").
 *
 * Before this, every one of these lived only in backend.env, so rotating a
 * leaked key or raising the AI cap in exam week meant an SSH session and a
 * config re-cache. Super admin only, enforced on the route group.
 *
 * Secrets are write-only. The console can set, replace or clear a key and can
 * see that one is set and its last four characters — enough to tell which key
 * is live — but no response ever carries a key back. A console that displays
 * secrets turns every screenshot and shoulder into a leak.
 */
class PlatformSettingsController extends Controller
{
    public function __construct(private TenantContext $tenant)
    {
    }

    public function index()
    {
        return response()->json($this->payload());
    }

    /**
     * Save any number of settings in one request.
     *
     * `values` maps keys to new values. A secret sent empty is left unchanged,
     * so a form can re-submit without re-typing every key; to remove a secret,
     * name it in `clear`. Any other setting sent empty reverts to the server's
     * own value.
     */
    public function update(Request $request)
    {
        $request->validate([
            'values' => 'array',
            'clear' => 'array',
            'clear.*' => 'string',
        ]);

        $definitions = PlatformSettings::definitions();
        $values = $request->input('values', []);
        $clear = $request->input('clear', []);

        $unknown = array_values(array_diff(array_merge(array_keys($values), $clear), array_keys($definitions)));

        if ($unknown !== []) {
            return response()->json([
                'message' => 'Those are not settings the console manages.',
                'errors' => ['values' => ['Unknown: '.implode(', ', $unknown)]],
            ], 422);
        }

        $errors = [];
        $toStore = [];
        $toClear = $clear;

        foreach ($values as $key => $raw) {
            $definition = $definitions[$key];
            $empty = $raw === null || (is_string($raw) && trim($raw) === '');

            if ($empty) {
                if ($definition['type'] !== 'secret') {
                    $toClear[] = $key;
                }

                continue;
            }

            $result = $this->normalise($key, $definition, $raw);

            if (is_array($result)) {
                $errors["values.{$key}"] = $result;
            } else {
                $toStore[$key] = $result;
            }
        }

        if ($errors !== []) {
            return response()->json(['message' => 'Some settings were not valid.', 'errors' => $errors], 422);
        }

        $userId = $request->user()->id;

        DB::transaction(function () use ($toStore, $toClear, $userId) {
            foreach ($toStore as $key => $value) {
                PlatformSettings::set($key, $value, $userId);
            }

            foreach (array_unique($toClear) as $key) {
                PlatformSettings::set($key, null, $userId);
            }
        });

        if ($toStore !== [] || $toClear !== []) {
            // Which keys changed, never what they changed to.
            AuditLog::create([
                'school_id' => null,
                'user_id' => $userId,
                'action' => 'platform.settings.updated',
                'auditable_type' => PlatformSetting::class,
                'auditable_id' => 0,
                'new_values' => [
                    'changed' => array_keys($toStore),
                    'cleared' => array_values(array_unique($toClear)),
                ],
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
            ]);

            /*
             * Web and API requests boot fresh and read the new values at once.
             * The queue worker is one long-lived process that loaded config
             * when it started, so without this it would keep sending SMS with
             * a revoked key until its hourly recycle.
             */
            try {
                Artisan::call('queue:restart');
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return response()->json(['message' => 'Settings saved.'] + $this->payload());
    }

    /**
     * Today's AI spend, school by school, against the cap.
     *
     * The directory shows a month's total; this is the view for the question
     * that actually comes in by phone — "why has the AI stopped working for us"
     * — which is nearly always a school that has hit today's cap.
     */
    public function aiUsage()
    {
        return $this->tenant->withoutScoping(function () {
            $today = now()->toDateString();
            $cap = config('services.anthropic.daily_cap_kobo');
            $capKobo = is_numeric($cap) && (int) $cap > 0 ? (int) $cap : null;

            // whereDate, not an exact match: the `date` cast writes a time
            // component, and only Postgres' date column would coerce it away.
            $rows = AiUsageDaily::query()
                ->whereDate('usage_date', $today)
                ->orderByDesc('cost_kobo')
                ->limit(50)
                ->get();

            $schools = School::query()
                ->whereIn('id', $rows->pluck('school_id'))
                ->get(['id', 'name', 'subdomain', 'ai_enabled'])
                ->keyBy('id');

            return response()->json([
                'date' => $today,
                'cap_naira' => $capKobo === null ? null : $capKobo / 100,
                'schools_with_ai_enabled' => School::query()->tenants()->where('ai_enabled', true)->count(),
                'data' => $rows->map(function (AiUsageDaily $row) use ($schools, $capKobo) {
                    $school = $schools->get($row->school_id);

                    return [
                        'school_id' => $row->school_id,
                        'school' => $school?->name,
                        'subdomain' => $school?->subdomain,
                        'ai_enabled' => (bool) $school?->ai_enabled,
                        'calls' => (int) $row->calls,
                        'spent_naira' => round($row->cost_kobo / 100, 2),
                        'cap_used_percentage' => $capKobo === null
                            ? null
                            : round(($row->cost_kobo / $capKobo) * 100, 1),
                        'at_cap' => $capKobo !== null && $row->cost_kobo >= $capKobo,
                    ];
                })->values(),
            ]);
        });
    }

    /**
     * Turn AI features on or off for one school.
     *
     * The platform-level switch for a school that has not paid for AI, or
     * whose usage needs stopping while a conversation happens — the cap limits
     * a day's spend, this stops it outright.
     */
    public function setSchoolAi(Request $request, int $schoolId)
    {
        $validated = $request->validate(['enabled' => 'required|boolean']);

        return $this->tenant->withoutScoping(function () use ($request, $schoolId, $validated) {
            $school = School::query()->tenants()->find($schoolId);

            if (! $school) {
                return response()->json(['message' => 'School not found'], 404);
            }

            $previous = (bool) $school->ai_enabled;
            $school->ai_enabled = $validated['enabled'];
            $school->save();

            AuditLog::create([
                'school_id' => $school->id,
                'user_id' => $request->user()->id,
                'action' => 'platform.school.ai_toggled',
                'auditable_type' => School::class,
                'auditable_id' => $school->id,
                'old_values' => ['ai_enabled' => $previous],
                'new_values' => ['ai_enabled' => (bool) $school->ai_enabled],
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
            ]);

            return response()->json([
                'message' => $school->ai_enabled ? "AI enabled for {$school->name}." : "AI disabled for {$school->name}.",
                'school' => ['id' => $school->id, 'ai_enabled' => (bool) $school->ai_enabled],
            ]);
        });
    }

    /**
     * Validate and convert one incoming value to the string that is stored.
     *
     * @return string|array<int, string> the stored form, or validation errors
     */
    private function normalise(string $key, array $definition, mixed $raw): string|array
    {
        $type = $definition['type'];

        switch ($type) {
            case 'bool':
                $bool = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

                return $bool === null ? ['Must be true or false.'] : ($bool ? '1' : '0');

            case 'naira':
                if (! is_numeric($raw) || (float) $raw < 0) {
                    return ['Enter an amount in Naira, 0 or more.'];
                }

                // Entered in Naira; every money column here is kobo.
                return (string) (int) round((float) $raw * 100);

            case 'number':
                if (! is_numeric($raw)) {
                    return ['Enter a number.'];
                }

                $number = (float) $raw;

                if (isset($definition['min']) && $number < $definition['min']) {
                    return ["Must be at least {$definition['min']}."];
                }

                if (isset($definition['max']) && $number > $definition['max']) {
                    return ["Must be at most {$definition['max']}."];
                }

                return (string) $number;

            case 'integer':
                if (filter_var($raw, FILTER_VALIDATE_INT) === false) {
                    return ['Enter a whole number.'];
                }

                $integer = (int) $raw;

                if (isset($definition['min']) && $integer < $definition['min']) {
                    return ["Must be at least {$definition['min']}."];
                }

                if (isset($definition['max']) && $integer > $definition['max']) {
                    return ["Must be at most {$definition['max']}."];
                }

                return (string) $integer;

            case 'email':
                $address = trim((string) $raw);

                return filter_var($address, FILTER_VALIDATE_EMAIL) ? $address : ['Enter a valid email address.'];

            case 'select':
                return in_array($raw, $definition['options'], true)
                    ? (string) $raw
                    : ['Choose one of: '.implode(', ', $definition['options']).'.'];

            case 'string':
                $string = trim((string) $raw);
                $max = $definition['max'] ?? 255;

                return mb_strlen($string) > $max ? ["At most {$max} characters."] : $string;

            case 'secret':
                $secret = trim((string) $raw);

                if (mb_strlen($secret) > 20000) {
                    return ['That is too long to be a key.'];
                }

                /*
                 * A service-account file that is not valid JSON, or is missing
                 * the two fields FCM signs with, would save without complaint
                 * and then fail on the first push — silently, from the school's
                 * side. Checked here instead.
                 */
                if ($key === 'services.fcm.credentials') {
                    $json = json_decode($secret, true);

                    if (! is_array($json) || empty($json['client_email']) || empty($json['private_key'])) {
                        return ['Paste the whole service-account JSON file — it needs client_email and private_key.'];
                    }
                }

                return $secret;
        }

        return ['Unsupported setting.'];
    }

    private function payload(): array
    {
        $rows = PlatformSetting::query()
            ->with('editor:id,name')
            ->get()
            ->keyBy('key');

        $groups = [];

        foreach (PlatformSettings::groups() as $group => $label) {
            $groups[$group] = ['key' => $group, 'label' => $label, 'settings' => []];
        }

        foreach (PlatformSettings::definitions() as $key => $definition) {
            $row = $rows->get($key);
            $readable = true;

            if ($row) {
                try {
                    // Touch the attribute: the cast decrypts, or throws for a
                    // value stored under an APP_KEY this box no longer has.
                    Crypt::decryptString($row->getRawOriginal('value'));
                } catch (DecryptException) {
                    $readable = false;
                }
            }

            $effective = config($key);
            $server = PlatformSettings::serverValue($key);
            $isSet = $effective !== null && $effective !== '' && $effective !== 0;

            $source = match (true) {
                $row !== null && ! $readable => 'unreadable',
                $row !== null => 'console',
                $server !== null && $server !== '' => 'server',
                default => 'unset',
            };

            $setting = [
                'key' => $key,
                'label' => $definition['label'],
                'type' => $definition['type'],
                'help' => $definition['help'] ?? null,
                'options' => $definition['options'] ?? null,
                'min' => $definition['min'] ?? null,
                'max' => $definition['max'] ?? null,
                'multiline' => $definition['multiline'] ?? false,
                'source' => $source,
                'is_set' => $isSet,
                'updated_at' => $row?->updated_at?->toIso8601String(),
                'updated_by' => $row?->editor?->name,
            ];

            if ($definition['type'] === 'secret') {
                $setting['value'] = null;
                $setting['hint'] = $isSet ? $this->hint($key, (string) $effective) : null;
            } else {
                $setting['value'] = $this->present($definition['type'], $effective);
                $setting['server_value'] = $this->present($definition['type'], $server);
            }

            $groups[$definition['group']]['settings'][] = $setting;
        }

        return ['groups' => array_values($groups)];
    }

    /** Enough to tell which key is live, and nothing that helps use it. */
    private function hint(string $key, string $secret): string
    {
        if ($key === 'services.fcm.credentials') {
            $json = json_decode($secret, true);

            // The service account's address identifies which Firebase project
            // is wired up, and is not itself a credential.
            if (is_array($json) && ! empty($json['client_email'])) {
                return $json['client_email'];
            }

            return 'Set';
        }

        return mb_strlen($secret) >= 12 ? '••••'.mb_substr($secret, -4) : 'Set';
    }

    private function present(string $type, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($type) {
            'naira' => is_numeric($value) ? round(((int) $value) / 100, 2) : null,
            'bool' => (bool) $value,
            'number' => is_numeric($value) ? (float) $value : null,
            'integer' => is_numeric($value) ? (int) $value : null,
            default => (string) $value,
        };
    }
}
