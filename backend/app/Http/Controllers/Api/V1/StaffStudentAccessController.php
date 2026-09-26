<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Http\Request;

/**
 * Letting a teacher see the whole school's students, or taking it back.
 *
 * A teacher sees the classes they teach. Some staff need more — an exams
 * officer compiling every broadsheet, a head of section — and the school admin
 * says so here, on the record. School admins already see everyone; the grant
 * is for teachers only.
 */
class StaffStudentAccessController extends Controller
{
    public function __construct(private TenantContext $tenant)
    {
    }

    public function update(Request $request, int $userId)
    {
        $validated = $request->validate([
            'sees_all_students' => 'required|boolean',
        ]);

        $schoolId = $this->tenant->schoolId();

        $user = User::with('userProfile')
            ->whereHas('userProfile', fn ($q) => $q->where('school_id', $schoolId))
            ->find($userId);

        if (! $user) {
            return response()->json(['message' => 'Staff member not found'], 404);
        }

        if ($user->userProfile->role !== 'teacher') {
            return response()->json([
                'message' => 'Only a teacher can be given whole-school access. School admins already have it; other roles never do.',
            ], 422);
        }

        $previous = (bool) $user->userProfile->sees_all_students;
        $user->userProfile->update(['sees_all_students' => $validated['sees_all_students']]);

        if ($previous !== (bool) $validated['sees_all_students']) {
            AuditLog::create([
                'school_id' => $schoolId,
                'user_id' => $request->user()->id,
                'action' => 'staff.student_access_changed',
                'auditable_type' => User::class,
                'auditable_id' => $user->id,
                'old_values' => ['sees_all_students' => $previous],
                'new_values' => ['sees_all_students' => (bool) $validated['sees_all_students']],
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
            ]);
        }

        return response()->json([
            'message' => $validated['sees_all_students']
                ? "{$user->name} can now see every student in the school."
                : "{$user->name} now sees only the classes they teach.",
            'data' => ['id' => $user->id, 'sees_all_students' => (bool) $validated['sees_all_students']],
        ]);
    }
}
