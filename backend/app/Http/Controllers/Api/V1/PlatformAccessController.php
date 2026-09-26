<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PlatformSchoolAccess;
use App\Models\School;
use App\Support\TenantContext;
use Illuminate\Http\Request;

/**
 * An operator stepping into one school, on the record.
 *
 * "Open" used to set a cookie and nothing more, so an operator could read any
 * school's children for as long as they liked with no trace of having done
 * it. Opening a school now takes a reason, is audited, and lasts a fixed time
 * (`schoolpilot.operator_access_minutes`); `BindTenantFromUser` honours the
 * school header only while the grant is live.
 *
 * Super admin only, enforced on the route group.
 */
class PlatformAccessController extends Controller
{
    public function __construct(private TenantContext $tenant)
    {
    }

    public function open(Request $request, int $schoolId)
    {
        $validated = $request->validate([
            // Long enough that "x" or "check" is not a reason.
            'reason' => 'required|string|min:10|max:500',
        ], [
            'reason.min' => 'Say why you are opening this school, for example the support request you are answering.',
        ]);

        return $this->tenant->withoutScoping(function () use ($request, $schoolId, $validated) {
            $school = School::query()->tenants()->find($schoolId);

            if (! $school) {
                return response()->json(['message' => 'School not found'], 404);
            }

            $user = $request->user();

            // One school at a time: opening another ends the previous grant.
            $this->closeLive($request);

            $minutes = max(5, (int) config('schoolpilot.operator_access_minutes', 120));

            $access = PlatformSchoolAccess::create([
                'user_id' => $user->id,
                'school_id' => $school->id,
                'reason' => trim($validated['reason']),
                'expires_at' => now()->addMinutes($minutes),
                'ip_address' => $request->ip(),
            ]);

            AuditLog::create([
                'school_id' => $school->id,
                'user_id' => $user->id,
                'action' => 'platform.school.opened',
                'auditable_type' => PlatformSchoolAccess::class,
                'auditable_id' => $access->id,
                'new_values' => [
                    'reason' => $access->reason,
                    'expires_at' => $access->expires_at->toIso8601String(),
                ],
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
            ]);

            return response()->json([
                'message' => "{$school->name} is open until {$access->expires_at->format('H:i')}.",
                'access' => $this->present($access->setRelation('school', $school)),
            ], 201);
        });
    }

    /** Leave whichever school is open. Idempotent: nothing open is not an error. */
    public function close(Request $request)
    {
        return $this->tenant->withoutScoping(function () use ($request) {
            $closed = $this->closeLive($request);

            return response()->json([
                'message' => $closed > 0 ? 'Left the school.' : 'No school was open.',
                'closed' => $closed,
            ]);
        });
    }

    /**
     * Who opened which school, when and why — newest first.
     *
     * The audit log has the same events; this is the view an operator (or a
     * school asking "who at SchoolPilot looked at our records?") actually
     * wants, with each grant's full span on one line.
     */
    public function log(Request $request)
    {
        $validated = $request->validate([
            'school_id' => 'nullable|integer',
        ]);

        return $this->tenant->withoutScoping(function () use ($validated) {
            $query = PlatformSchoolAccess::query()
                ->with(['user:id,name,email', 'school:id,name,subdomain'])
                ->orderByDesc('id');

            if (! empty($validated['school_id'])) {
                $query->where('school_id', $validated['school_id']);
            }

            $page = $query->paginate(50);

            return response()->json([
                'data' => collect($page->items())->map(fn (PlatformSchoolAccess $row) => $this->present($row)),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'total' => $page->total(),
                ],
            ]);
        });
    }

    /** Close every live grant this operator holds, auditing each. */
    private function closeLive(Request $request): int
    {
        $live = PlatformSchoolAccess::query()->live()->where('user_id', $request->user()->id)->get();

        foreach ($live as $access) {
            $access->update(['closed_at' => now()]);

            AuditLog::create([
                'school_id' => $access->school_id,
                'user_id' => $request->user()->id,
                'action' => 'platform.school.closed',
                'auditable_type' => PlatformSchoolAccess::class,
                'auditable_id' => $access->id,
                'new_values' => ['closed_at' => $access->closed_at->toIso8601String()],
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
            ]);
        }

        return $live->count();
    }

    private function present(PlatformSchoolAccess $access): array
    {
        $ended = $access->closed_at ?? ($access->expires_at->isPast() ? $access->expires_at : null);

        return [
            'id' => $access->id,
            'school' => $access->school ? [
                'id' => $access->school->id,
                'name' => $access->school->name,
                'subdomain' => $access->school->subdomain,
            ] : null,
            'operator' => $access->user ? [
                'id' => $access->user->id,
                'name' => $access->user->name,
                'email' => $access->user->email,
            ] : null,
            'reason' => $access->reason,
            'opened_at' => $access->created_at?->toIso8601String(),
            'expires_at' => $access->expires_at->toIso8601String(),
            'ended_at' => $ended?->toIso8601String(),
            'is_live' => $ended === null,
        ];
    }
}
