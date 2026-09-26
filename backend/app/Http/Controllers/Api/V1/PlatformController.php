<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AiUsageDaily;
use App\Models\CbtAttempt;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The SchoolPilot operator's own console.
 *
 * Everything here is deliberately cross-tenant, which is why it is the one
 * controller that reads `schools` in aggregate. A super admin has no
 * `school_id`, so none of the school-side dashboards mean anything for them:
 * `/analytics/principal` scopes to `$user->userProfile->school_id`, which is
 * null, and would report a platform operator an entire school's worth of
 * zeroes. That is the bug this controller exists to close — the operator
 * console and a school's own dashboard answer different questions and must not
 * be the same screen.
 *
 * Super admin only, enforced on the route group.
 */
class PlatformController extends Controller
{
    public function __construct(private TenantContext $tenant)
    {
    }

    /**
     * Platform-wide totals for the console's top row.
     *
     * Counts are computed across every tenant in one query each rather than by
     * walking schools, because this is the page an operator loads first and a
     * per-school loop would be one query per school on every render.
     */
    public function overview()
    {
        return $this->tenant->withoutScoping(function () {
            /*
             * `tenants()` excludes SchoolPilot's own row. That row exists only
             * to give the operator a subdomain to sign in through, and counting
             * it reported "Schools 1" for a platform with no customers on it.
             */
            $schoolsByStatus = School::query()
                ->tenants()
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status');

            $studentsByStatus = Student::query()
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status');

            // Fees collected by schools from parents. This is the money moving
            // through the platform, not SchoolPilot's own revenue — the two get
            // conflated easily and they are not the same number.
            $collected = (float) Payment::query()->where('status', 'successful')->sum('amount');
            $invoiced = (float) Invoice::query()->sum('total_amount');

            $monthStart = now()->startOfMonth()->toDateString();

            // Stored in kobo precisely so a spend figure is never a float.
            $aiSpendKoboThisMonth = (int) AiUsageDaily::query()
                ->where('usage_date', '>=', $monthStart)
                ->sum('cost_kobo');

            $aiCallsThisMonth = (int) AiUsageDaily::query()
                ->where('usage_date', '>=', $monthStart)
                ->sum('calls');

            return response()->json([
                'schools' => [
                    'total' => (int) $schoolsByStatus->sum(),
                    'active' => (int) ($schoolsByStatus['active'] ?? 0),
                    'trial' => (int) ($schoolsByStatus['trial'] ?? 0),
                    'suspended' => (int) ($schoolsByStatus['suspended'] ?? 0),
                ],
                'students' => [
                    'total' => (int) $studentsByStatus->sum(),
                    'active' => (int) ($studentsByStatus['active'] ?? 0),
                    'by_status' => $studentsByStatus,
                ],
                'staff' => [
                    'total' => (int) User::query()->count(),
                ],
                // Named for what it is. An earlier version of this screen
                // labelled school fee collections "Platform MRR", which
                // overstated SchoolPilot's own income by roughly the entire
                // size of its customers' fee books.
                'school_fee_flow' => [
                    'collected' => round($collected, 2),
                    'invoiced' => round($invoiced, 2),
                    'recovery_rate_percentage' => $invoiced > 0
                        ? round(($collected / $invoiced) * 100, 2)
                        : 0.0,
                    'currency' => 'NGN',
                ],
                'ai_spend_this_month' => [
                    'cost_kobo' => $aiSpendKoboThisMonth,
                    'cost_naira' => round($aiSpendKoboThisMonth / 100, 2),
                    'calls' => $aiCallsThisMonth,
                    'currency' => 'NGN',
                    'since' => $monthStart,
                ],
                'cbt' => [
                    'attempts_all_time' => (int) CbtAttempt::query()
                        ->whereIn('status', ['submitted', 'graded'])
                        ->count(),
                ],
                'generated_at' => now()->toIso8601String(),
            ]);
        });
    }

    /**
     * The tenant directory.
     *
     * Student counts and AI spend come back as grouped sub-queries keyed by
     * school rather than as `withCount` on a tenant-scoped relation: `students`
     * carries the global scope, and a relation count would have applied the
     * operator's own (absent) tenant to it.
     */
    public function schools(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $status = $request->query('status');

        return $this->tenant->withoutScoping(function () use ($search, $status) {
            // Customers only — see overview() above.
            $query = School::query()->tenants()->orderBy('name');

            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('subdomain', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            }

            if (in_array($status, ['active', 'trial', 'suspended'], true)) {
                $query->where('status', $status);
            }

            $schools = $query->paginate(50);

            $ids = collect($schools->items())->pluck('id');

            $studentCounts = Student::query()
                ->whereIn('school_id', $ids)
                ->where('status', 'active')
                ->selectRaw('school_id, COUNT(*) as total')
                ->groupBy('school_id')
                ->pluck('total', 'school_id');

            $collected = Payment::query()
                ->whereIn('school_id', $ids)
                ->where('status', 'successful')
                ->selectRaw('school_id, SUM(amount) as total')
                ->groupBy('school_id')
                ->pluck('total', 'school_id');

            $aiSpend = AiUsageDaily::query()
                ->whereIn('school_id', $ids)
                ->where('usage_date', '>=', now()->startOfMonth()->toDateString())
                ->selectRaw('school_id, SUM(cost_kobo) as total')
                ->groupBy('school_id')
                ->pluck('total', 'school_id');

            $data = collect($schools->items())->map(function (School $school) use ($studentCounts, $collected, $aiSpend) {
                $students = (int) ($studentCounts[$school->id] ?? 0);

                return [
                    'id' => $school->id,
                    'name' => $school->name,
                    'subdomain' => $school->subdomain,
                    'email' => $school->email,
                    'status' => $school->status,
                    'active_students' => $students,
                    // Derived, never stored: a band is a function of the roll,
                    // so persisting it would let the two drift apart.
                    'plan_band' => $this->planBand($students),
                    'ai_enabled' => (bool) $school->ai_enabled,
                    'result_checker_enabled' => (bool) $school->result_checker_enabled,
                    'fees_collected' => round((float) ($collected[$school->id] ?? 0), 2),
                    'ai_spend_naira_this_month' => round(((int) ($aiSpend[$school->id] ?? 0)) / 100, 2),
                    'created_at' => $school->created_at?->toIso8601String(),
                ];
            });

            return response()->json([
                'data' => $data,
                'meta' => [
                    'current_page' => $schools->currentPage(),
                    'last_page' => $schools->lastPage(),
                    'per_page' => $schools->perPage(),
                    'total' => $schools->total(),
                ],
            ]);
        });
    }

    /**
     * One tenant, in enough detail to answer a support call.
     */
    public function show(int $schoolId)
    {
        return $this->tenant->withoutScoping(function () use ($schoolId) {
            $school = School::find($schoolId);

            if (! $school) {
                return response()->json(['message' => 'School not found'], 404);
            }

            $students = Student::query()
                ->where('school_id', $school->id)
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status');

            $staff = DB::table('user_profiles')
                ->where('school_id', $school->id)
                ->selectRaw('role, COUNT(*) as total')
                ->groupBy('role')
                ->pluck('total', 'role');

            $collected = (float) Payment::query()
                ->where('school_id', $school->id)
                ->where('status', 'successful')
                ->sum('amount');

            $invoiced = (float) Invoice::query()
                ->where('school_id', $school->id)
                ->sum('total_amount');

            $activeStudents = (int) ($students['active'] ?? 0);

            return response()->json([
                'school' => [
                    'id' => $school->id,
                    'name' => $school->name,
                    'subdomain' => $school->subdomain,
                    'email' => $school->email,
                    'phone' => $school->phone,
                    'address' => $school->address,
                    'status' => $school->status,
                    'ai_enabled' => (bool) $school->ai_enabled,
                    'result_checker_enabled' => (bool) $school->result_checker_enabled,
                    'plan_band' => $this->planBand($activeStudents),
                    'created_at' => $school->created_at?->toIso8601String(),
                ],
                'students' => [
                    'active' => $activeStudents,
                    'total' => (int) $students->sum(),
                    'by_status' => $students,
                ],
                'staff_by_role' => $staff,
                'fee_flow' => [
                    'collected' => round($collected, 2),
                    'invoiced' => round($invoiced, 2),
                    'outstanding' => round(max($invoiced - $collected, 0), 2),
                    'currency' => 'NGN',
                ],
                'ai_spend_naira_this_month' => round(((int) AiUsageDaily::query()
                    ->where('school_id', $school->id)
                    ->where('usage_date', '>=', now()->startOfMonth()->toDateString())
                    ->sum('cost_kobo')) / 100, 2),
            ]);
        });
    }

    /**
     * Suspend or reinstate a tenant.
     *
     * Suspension is not deletion: a suspended school keeps every record, so a
     * school that falls behind on payment and then settles is not a data-loss
     * event.
     */
    public function setStatus(Request $request, int $schoolId)
    {
        $validated = $request->validate([
            'status' => 'required|in:active,trial,suspended',
        ]);

        return $this->tenant->withoutScoping(function () use ($schoolId, $validated, $request) {
            $school = School::find($schoolId);

            if (! $school) {
                return response()->json(['message' => 'School not found'], 404);
            }

            /*
             * SchoolPilot's own row is not a tenant and cannot be suspended.
             *
             * Every login resolves a tenant from the host, the operator's
             * included, so suspending this row would block sign-in through the
             * subdomain the operator uses — locking them out of the console
             * that has the button. There is no way back in from the browser.
             */
            if ($school->is_platform) {
                return response()->json([
                    'message' => 'That is SchoolPilot\'s own record, not a school. Suspending it would block operator sign-in.',
                ], 422);
            }

            $previous = $school->status;
            $school->status = $validated['status'];
            $school->save();

            \App\Models\AuditLog::create([
                'school_id' => $school->id,
                'user_id' => $request->user()->id,
                'action' => 'platform.school.status_changed',
                'auditable_type' => School::class,
                'auditable_id' => $school->id,
                'old_values' => ['status' => $previous],
                'new_values' => ['status' => $school->status],
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
            ]);

            return response()->json([
                'message' => 'School status updated',
                'school' => [
                    'id' => $school->id,
                    'name' => $school->name,
                    'status' => $school->status,
                ],
            ]);
        });
    }

    /**
     * Pricing bands from doc §13, by active roll.
     */
    private function planBand(int $activeStudents): string
    {
        return match (true) {
            $activeStudents <= 200 => 'Starter (0-200)',
            $activeStudents <= 500 => 'Standard (201-500)',
            $activeStudents <= 1000 => 'Premium (501-1,000)',
            default => 'Enterprise (1,000+)',
        };
    }
}
