<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\School;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Http\Request;

/**
 * Platform security (doc §5): who holds an administrator account, whether it is
 * protected by 2FA, and what has been done with the powers those accounts have.
 *
 * Every row in `audit_logs` was already being written — provisioning, status
 * changes, key rotations, PIN grants, student and result edits — and nothing
 * read any of it back. An operator asked "who suspended this school?" had to
 * query the database.
 *
 * Super admin only, enforced on the route group.
 */
class PlatformSecurityController extends Controller
{
    /** Administrator roles, the ones whose compromise reaches a whole school or more. */
    private const ADMIN_ROLES = ['school_admin', 'super_admin'];

    public function __construct(private TenantContext $tenant)
    {
    }

    /**
     * The audit trail across every school.
     *
     * Values are returned only for the platform's own actions. A school-side
     * row — a student record edited, a score changed — carries the child's
     * personal data in `old_values`/`new_values`, and an operator reviewing
     * "what happened" needs which fields changed, not the child's details
     * (NDPA data minimisation, doc §12). To see the record itself they open the
     * school, which is its own audited step.
     */
    public function auditLog(Request $request)
    {
        $validated = $request->validate([
            'school_id' => 'nullable|integer',
            'action' => 'nullable|string|max:100',
            'scope' => 'nullable|in:platform,school',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        return $this->tenant->withoutScoping(function () use ($validated) {
            $query = AuditLog::query()
                ->with('user:id,name,email')
                ->orderByDesc('created_at')
                ->orderByDesc('id');

            if (! empty($validated['school_id'])) {
                $query->where('school_id', $validated['school_id']);
            }

            if (! empty($validated['action'])) {
                $query->where('action', 'like', $validated['action'].'%');
            }

            if (($validated['scope'] ?? null) === 'platform') {
                $query->where('action', 'like', 'platform.%');
            } elseif (($validated['scope'] ?? null) === 'school') {
                $query->where('action', 'not like', 'platform.%');
            }

            if (! empty($validated['from'])) {
                $query->where('created_at', '>=', \Carbon\Carbon::parse($validated['from'])->startOfDay());
            }

            if (! empty($validated['to'])) {
                $query->where('created_at', '<=', \Carbon\Carbon::parse($validated['to'])->endOfDay());
            }

            $page = $query->paginate(50);

            $schools = School::query()
                ->whereIn('id', collect($page->items())->pluck('school_id')->filter()->unique())
                ->get(['id', 'name', 'subdomain'])
                ->keyBy('id');

            $data = collect($page->items())->map(function (AuditLog $row) use ($schools) {
                $isPlatformAction = str_starts_with($row->action, 'platform.');
                $school = $row->school_id ? $schools->get($row->school_id) : null;

                return [
                    'id' => $row->id,
                    'at' => $row->created_at?->toIso8601String(),
                    'action' => $row->action,
                    'school' => $school ? [
                        'id' => $school->id,
                        'name' => $school->name,
                        'subdomain' => $school->subdomain,
                    ] : null,
                    'actor' => $row->user ? [
                        'id' => $row->user->id,
                        'name' => $row->user->name,
                        'email' => $row->user->email,
                    ] : null,
                    'record' => class_basename($row->auditable_type).' #'.$row->auditable_id,
                    'changed_fields' => array_values(array_unique(array_merge(
                        array_keys((array) $row->old_values),
                        array_keys((array) $row->new_values),
                    ))),
                    'old_values' => $isPlatformAction ? $row->old_values : null,
                    'new_values' => $isPlatformAction ? $row->new_values : null,
                    'ip_address' => $row->ip_address,
                ];
            });

            return response()->json([
                'data' => $data,
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                ],
            ]);
        });
    }

    /**
     * Every administrator account on the platform, with its 2FA and session
     * state.
     *
     * The per-user security view (`/users/{id}/security`) answers for one person
     * in one school. This answers the operator's question — which admin
     * accounts, anywhere, are one leaked password away from a whole school.
     */
    public function admins(Request $request)
    {
        $validated = $request->validate([
            'school_id' => 'nullable|integer',
            'two_factor' => 'nullable|in:on,off',
            'search' => 'nullable|string|max:100',
        ]);

        return $this->tenant->withoutScoping(function () use ($validated) {
            $base = User::query()
                ->whereHas('userProfile', fn ($q) => $q->whereIn('role', self::ADMIN_ROLES));

            $summary = [
                'admins' => (clone $base)->count(),
                'with_two_factor' => (clone $base)
                    ->whereHas('userProfile', fn ($q) => $q->where('two_factor_enabled', true))
                    ->count(),
                'locked' => (clone $base)->where('locked_until', '>', now())->count(),
                // Whether sign-in refuses an admin without 2FA. Off unless
                // AUTH_REQUIRE_ADMIN_2FA is set on the server.
                'two_factor_required' => (bool) config('auth.require_admin_two_factor', false),
            ];
            $summary['without_two_factor'] = $summary['admins'] - $summary['with_two_factor'];

            $query = (clone $base)
                ->with('userProfile:id,user_id,school_id,role,two_factor_enabled')
                ->withCount('tokens')
                ->orderBy('name');

            if (! empty($validated['school_id'])) {
                $query->whereHas('userProfile', fn ($q) => $q->where('school_id', $validated['school_id']));
            }

            if (($validated['two_factor'] ?? null) !== null) {
                $enabled = $validated['two_factor'] === 'on';
                $query->whereHas('userProfile', fn ($q) => $q->where('two_factor_enabled', $enabled));
            }

            if (! empty($validated['search'])) {
                $term = $validated['search'];
                $query->where(fn ($q) => $q->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%"));
            }

            $page = $query->paginate(50);

            $schools = School::query()
                ->whereIn('id', collect($page->items())->pluck('userProfile.school_id')->filter()->unique())
                ->get(['id', 'name', 'subdomain', 'is_platform'])
                ->keyBy('id');

            $data = collect($page->items())->map(function (User $user) use ($schools) {
                $school = $schools->get($user->userProfile?->school_id);

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->userProfile?->role,
                    'school' => $school && ! $school->is_platform ? [
                        'id' => $school->id,
                        'name' => $school->name,
                        'subdomain' => $school->subdomain,
                    ] : null,
                    'two_factor_enabled' => (bool) $user->userProfile?->two_factor_enabled,
                    'active_sessions' => (int) $user->tokens_count,
                    'last_login_at' => $user->last_login_at?->toIso8601String(),
                    'locked_until' => $user->locked_until && $user->locked_until->isFuture()
                        ? $user->locked_until->toIso8601String()
                        : null,
                    'status' => $user->status,
                ];
            });

            return response()->json([
                'summary' => $summary,
                'data' => $data,
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                ],
            ]);
        });
    }

    /**
     * Sign an administrator out everywhere — the first move when an admin
     * account is thought to be compromised.
     *
     * Only administrator accounts: a teacher's or parent's sessions are the
     * school's to manage, through its own user screens. Not the operator's own
     * account either, which would end the request that asked for it; they sign
     * out instead.
     */
    public function revokeSessions(Request $request, int $userId)
    {
        return $this->tenant->withoutScoping(function () use ($request, $userId) {
            $user = User::with('userProfile')->find($userId);

            if (! $user || ! in_array($user->userProfile?->role, self::ADMIN_ROLES, true)) {
                return response()->json(['message' => 'Administrator not found'], 404);
            }

            if ($user->id === $request->user()->id) {
                return response()->json([
                    'message' => 'That is your own account. Sign out instead.',
                ], 422);
            }

            $revoked = $user->tokens()->count();
            $user->tokens()->delete();

            AuditLog::create([
                'school_id' => $user->userProfile->school_id,
                'user_id' => $request->user()->id,
                'action' => 'platform.user.sessions_revoked',
                'auditable_type' => User::class,
                'auditable_id' => $user->id,
                'new_values' => ['sessions_revoked' => $revoked],
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
            ]);

            return response()->json([
                'message' => "{$user->name} has been signed out of {$revoked} session(s).",
                'sessions_revoked' => $revoked,
            ]);
        });
    }
}
