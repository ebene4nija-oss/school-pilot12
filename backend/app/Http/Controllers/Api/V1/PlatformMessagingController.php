<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\NotificationLog;
use App\Models\School;
use App\Models\SchoolSmsGateway;
use App\Services\NotificationService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

/**
 * The operator's control over how much each school sends.
 *
 * Every school's email leaves through one sending domain, so one runaway
 * broadcast can get that domain blocklisted for all of them. The platform sets
 * each school's email on/off and daily limit; a school admin cannot, because a
 * cap the capped party can raise is not a cap. Super admin only.
 */
class PlatformMessagingController extends Controller
{
    public function __construct(private TenantContext $tenant)
    {
    }

    /** Every school's messaging state, busiest first. */
    public function index()
    {
        return $this->tenant->withoutScoping(function () {
            $today = now()->startOfDay();

            $sentToday = NotificationLog::query()
                ->where('status', 'sent')
                ->where('sent_at', '>=', $today)
                ->selectRaw('school_id, channel, COUNT(*) as total')
                ->groupBy('school_id', 'channel')
                ->get()
                ->groupBy('school_id');

            $failedToday = NotificationLog::query()
                ->where('status', 'failed')
                ->where('created_at', '>=', $today)
                ->selectRaw('school_id, COUNT(*) as total')
                ->groupBy('school_id')
                ->pluck('total', 'school_id');

            $gateways = SchoolSmsGateway::query()->get()->keyBy('school_id');

            $schools = School::query()->tenants()->orderBy('name')->get()->map(function (School $school) use ($sentToday, $failedToday, $gateways) {
                $counts = ($sentToday->get($school->id) ?? collect())->pluck('total', 'channel');
                $gateway = $gateways->get($school->id);

                return [
                    'id' => $school->id,
                    'name' => $school->name,
                    'subdomain' => $school->subdomain,
                    'email_enabled' => (bool) $school->email_enabled,
                    // Null here means "uses the platform default".
                    'email_daily_limit' => $school->email_daily_limit,
                    'effective_email_limit' => NotificationService::emailDailyLimit($school),
                    'sent_today' => [
                        'email' => (int) ($counts['email'] ?? 0),
                        'sms' => (int) ($counts['sms'] ?? 0),
                        'push' => (int) ($counts['push'] ?? 0),
                        'whatsapp' => (int) ($counts['whatsapp'] ?? 0),
                    ],
                    'failed_today' => (int) ($failedToday[$school->id] ?? 0),
                    'sms_account' => $gateway ? [
                        'provider' => $gateway->provider,
                        'is_active' => $gateway->is_active,
                        'last_test_status' => $gateway->last_test_status,
                    ] : null,
                ];
            })->sortByDesc(fn ($row) => array_sum($row['sent_today']))->values();

            $default = config('schoolpilot.email_daily_limit');

            return response()->json([
                'default_email_limit' => is_numeric($default) && (int) $default > 0 ? (int) $default : null,
                'data' => $schools,
            ]);
        });
    }

    /**
     * Switch a school's email on or off, or give it its own daily limit.
     *
     * `email_daily_limit: null` puts it back on the platform default.
     */
    public function update(Request $request, int $schoolId)
    {
        $validated = $request->validate([
            'email_enabled' => 'sometimes|boolean',
            'email_daily_limit' => 'sometimes|nullable|integer|min:0|max:1000000',
        ]);

        return $this->tenant->withoutScoping(function () use ($request, $schoolId, $validated) {
            $school = School::query()->tenants()->find($schoolId);

            if (! $school) {
                return response()->json(['message' => 'School not found'], 404);
            }

            $before = $school->only(['email_enabled', 'email_daily_limit']);
            $school->fill($validated)->save();

            AuditLog::create([
                'school_id' => $school->id,
                'user_id' => $request->user()->id,
                'action' => 'platform.school.messaging_changed',
                'auditable_type' => School::class,
                'auditable_id' => $school->id,
                'old_values' => $before,
                'new_values' => $school->only(['email_enabled', 'email_daily_limit']),
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
            ]);

            return response()->json([
                'message' => 'Messaging limits saved.',
                'school' => [
                    'id' => $school->id,
                    'email_enabled' => (bool) $school->email_enabled,
                    'email_daily_limit' => $school->email_daily_limit,
                    'effective_email_limit' => NotificationService::emailDailyLimit($school),
                ],
            ]);
        });
    }
}
