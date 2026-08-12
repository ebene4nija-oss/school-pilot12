<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CustomRole;
use App\Models\LoginHistory;
use App\Models\ParentProfile;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentPortfolio;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Models\UserProfile;
use App\Services\PasswordResetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class UserManagementController extends Controller
{
    private function getSchoolId(Request $request)
    {
        if ($request->user() && $request->user()->userProfile && $request->user()->userProfile->school_id) {
            return $request->user()->userProfile->school_id;
        }
        $tenant = $request->attributes->get('tenant_school');
        return $tenant ? $tenant->id : $request->attributes->get('school_id');
    }

    private function logActivity(Request $request, $userId, string $action, $target = null, array $metadata = [])
    {
        UserActivityLog::create([
            'school_id' => $this->getSchoolId($request),
            'user_id'   => $userId,
            'action'    => $action,
            'target_type' => $target ? get_class($target) : null,
            'target_id'   => $target ? $target->id : null,
            'metadata'    => $metadata,
            'ip_address'  => $request->ip(),
        ]);
    }

    // ─── User Listing, Search & Filtering ────────────────────────────

    public function listUsers(Request $request)
    {
        $schoolId = $this->getSchoolId($request);

        $query = User::whereHas('userProfile', function ($q) use ($schoolId) {
            $q->where('school_id', $schoolId);
        })->with('userProfile');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('email', 'ilike', "%{$search}%");
            });
        }

        if ($role = $request->query('role')) {
            $query->whereHas('userProfile', fn($q) => $q->where('role', $role));
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $perPage = min((int) $request->query('per_page', 15), 100);

        return response()->json($query->orderBy('name')->paginate($perPage));
    }

    public function showUser(Request $request, $id)
    {
        $schoolId = $this->getSchoolId($request);

        $user = User::whereHas('userProfile', fn($q) => $q->where('school_id', $schoolId))
            ->with([
                'userProfile',
                'customRoles',
                'teacherProfile',
                'parentProfile',
                'loginHistories' => fn($q) => $q->orderBy('login_at', 'desc')->limit(10),
            ])
            ->findOrFail($id);

        return response()->json(['data' => $user]);
    }

    // ─── User Status Lifecycle ───────────────────────────────────────

    public function updateUserStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:active,suspended,archived,pending',
            'reason' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $schoolId = $this->getSchoolId($request);
        $user = User::whereHas('userProfile', fn($q) => $q->where('school_id', $schoolId))->findOrFail($id);

        $oldStatus = $user->status;
        $user->update(['status' => $request->status]);

        if ($request->status === 'suspended') {
            $user->tokens()->delete();
        }

        $this->logActivity($request, $request->user()->id, 'user.status_changed', $user, [
            'old_status' => $oldStatus,
            'new_status' => $request->status,
            'reason'     => $request->reason,
        ]);

        return response()->json([
            'message' => "User status changed from '{$oldStatus}' to '{$request->status}'.",
            'data'    => $user,
        ]);
    }

    public function bulkUpdateStatus(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => 'integer|exists:users,id',
            'status' => 'required|in:active,suspended,archived',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $schoolId = $this->getSchoolId($request);
        $updated = 0;

        foreach ($request->user_ids as $userId) {
            $user = User::whereHas('userProfile', fn($q) => $q->where('school_id', $schoolId))->find($userId);
            if ($user) {
                $user->update(['status' => $request->status]);
                if ($request->status === 'suspended') {
                    $user->tokens()->delete();
                }
                $this->logActivity($request, $request->user()->id, 'user.bulk_status_changed', $user, [
                    'new_status' => $request->status,
                ]);
                $updated++;
            }
        }

        return response()->json([
            'message' => "Updated status to '{$request->status}' for {$updated} users.",
            'updated_count' => $updated,
        ]);
    }

    // ─── Profile Management & Completion Tracking ────────────────────

    public function updateProfile(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'phone'   => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
            'bio'     => 'nullable|string|max:1000',
            'date_of_birth' => 'nullable|date',
            'gender'  => 'nullable|string|in:male,female,other',
            'nationality' => 'nullable|string|max:100',
            'state_of_origin' => 'nullable|string|max:100',
            'lga' => 'nullable|string|max:100',
            'emergency_contact_name' => 'nullable|string|max:255',
            'emergency_contact_phone' => 'nullable|string|max:20',
            'avatar_url' => 'nullable|url|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $schoolId = $this->getSchoolId($request);
        $user = User::whereHas('userProfile', fn($q) => $q->where('school_id', $schoolId))->findOrFail($id);
        $profile = $user->userProfile;

        $profile->update($request->only([
            'phone', 'address', 'bio', 'date_of_birth', 'gender',
            'nationality', 'state_of_origin', 'lga',
            'emergency_contact_name', 'emergency_contact_phone', 'avatar_url',
        ]));

        // Recalculate profile completion
        $trackable = ['phone', 'address', 'bio', 'date_of_birth', 'gender', 'nationality', 'state_of_origin', 'lga', 'emergency_contact_name', 'emergency_contact_phone', 'avatar_url'];
        $filled = collect($trackable)->filter(fn($f) => !empty($profile->{$f}))->count();
        $profile->update(['profile_completion_percentage' => (int) round(($filled / count($trackable)) * 100)]);

        $this->logActivity($request, $request->user()->id, 'profile.updated', $profile);

        return response()->json([
            'message' => 'Profile updated successfully.',
            'data'    => $profile->fresh(),
        ]);
    }

    public function getProfileCompletion(Request $request, $id)
    {
        $schoolId = $this->getSchoolId($request);
        $user = User::whereHas('userProfile', fn($q) => $q->where('school_id', $schoolId))->findOrFail($id);
        $profile = $user->userProfile;

        $trackable = ['phone', 'address', 'bio', 'date_of_birth', 'gender', 'nationality', 'state_of_origin', 'lga', 'emergency_contact_name', 'emergency_contact_phone', 'avatar_url'];
        $completed = [];
        $missing = [];

        foreach ($trackable as $field) {
            if (!empty($profile->{$field})) {
                $completed[] = $field;
            } else {
                $missing[] = $field;
            }
        }

        return response()->json([
            'percentage' => $profile->profile_completion_percentage,
            'completed_fields' => $completed,
            'missing_fields' => $missing,
            'total_fields' => count($trackable),
        ]);
    }

    // ─── Custom Roles CRUD ───────────────────────────────────────────

    public function listRoles(Request $request)
    {
        $schoolId = $this->getSchoolId($request);
        $roles = CustomRole::where('school_id', $schoolId)->withCount('users')->get();
        return response()->json(['data' => $roles]);
    }

    public function createRole(Request $request)
    {
        $schoolId = $this->getSchoolId($request);

        $validator = Validator::make($request->all(), [
            'name'        => 'required|string|max:100',
            'slug'        => ['required', 'string', 'max:50', Rule::unique('custom_roles')->where('school_id', $schoolId)],
            'description' => 'nullable|string|max:500',
            'permissions' => 'required|array',
            'permissions.*' => 'string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $role = CustomRole::create([
            'school_id'   => $schoolId,
            'name'        => $request->name,
            'slug'        => $request->slug,
            'description' => $request->description,
            'permissions' => $request->permissions,
        ]);

        $this->logActivity($request, $request->user()->id, 'role.created', $role);

        return response()->json(['message' => 'Custom role created.', 'data' => $role], 201);
    }

    public function updateRole(Request $request, $id)
    {
        $schoolId = $this->getSchoolId($request);
        $role = CustomRole::where('school_id', $schoolId)->findOrFail($id);

        if ($role->is_system_default) {
            return response()->json(['error' => 'System default roles cannot be edited.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'name'        => 'sometimes|string|max:100',
            'description' => 'nullable|string|max:500',
            'permissions' => 'sometimes|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $role->update($request->only(['name', 'description', 'permissions']));
        $this->logActivity($request, $request->user()->id, 'role.updated', $role);

        return response()->json(['message' => 'Role updated.', 'data' => $role]);
    }

    public function deleteRole(Request $request, $id)
    {
        $schoolId = $this->getSchoolId($request);
        $role = CustomRole::where('school_id', $schoolId)->withCount('users')->findOrFail($id);

        if ($role->is_system_default) {
            return response()->json(['error' => 'System default roles cannot be deleted.'], 403);
        }

        if ($role->users_count > 0) {
            return response()->json(['error' => "Cannot delete role: {$role->users_count} users are still assigned."], 409);
        }

        $role->delete();
        $this->logActivity($request, $request->user()->id, 'role.deleted', null, ['role_name' => $role->name]);

        return response()->json(['message' => 'Role deleted.']);
    }

    public function assignRole(Request $request, $userId)
    {
        $validator = Validator::make($request->all(), [
            'custom_role_id' => 'required|exists:custom_roles,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $schoolId = $this->getSchoolId($request);
        $user = User::whereHas('userProfile', fn($q) => $q->where('school_id', $schoolId))->findOrFail($userId);

        $user->customRoles()->syncWithoutDetaching([
            $request->custom_role_id => [
                'assigned_by' => $request->user()->id,
                'assigned_at' => now(),
            ],
        ]);

        $this->logActivity($request, $request->user()->id, 'role.assigned', $user, [
            'custom_role_id' => $request->custom_role_id,
        ]);

        return response()->json([
            'message' => 'Role assigned successfully.',
            'data'    => $user->load('customRoles'),
        ]);
    }

    public function revokeRole(Request $request, $userId, $roleId)
    {
        $schoolId = $this->getSchoolId($request);
        $user = User::whereHas('userProfile', fn($q) => $q->where('school_id', $schoolId))->findOrFail($userId);

        $user->customRoles()->detach($roleId);
        $this->logActivity($request, $request->user()->id, 'role.revoked', $user, ['custom_role_id' => $roleId]);

        return response()->json(['message' => 'Role revoked.']);
    }

    // ─── Activity Timeline & Login History ───────────────────────────

    public function getUserTimeline(Request $request, $id)
    {
        $schoolId = $this->getSchoolId($request);
        User::whereHas('userProfile', fn($q) => $q->where('school_id', $schoolId))->findOrFail($id);

        $logs = UserActivityLog::where('school_id', $schoolId)
            ->where('user_id', $id)
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json($logs);
    }

    public function getLoginHistory(Request $request, $id)
    {
        $schoolId = $this->getSchoolId($request);
        User::whereHas('userProfile', fn($q) => $q->where('school_id', $schoolId))->findOrFail($id);

        $history = LoginHistory::where('user_id', $id)
            ->orderBy('login_at', 'desc')
            ->paginate(20);

        return response()->json($history);
    }

    // ─── Bulk Import & Export ────────────────────────────────────────

    public function bulkImportUsers(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:csv,txt',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $schoolId = $this->getSchoolId($request);
        $rows = array_map('str_getcsv', file($request->file('file')->getRealPath()));
        $header = array_shift($rows);

        $imported = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            $rowNum = $index + 2;
            if (count($row) < 3) {
                $errors[] = "Row {$rowNum}: Insufficient columns (need name, email, role).";
                continue;
            }

            $name  = trim($row[0]);
            $email = trim($row[1]);
            $role  = trim($row[2]);
            $phone = isset($row[3]) ? trim($row[3]) : null;

            if (User::where('email', $email)->exists()) {
                $errors[] = "Row {$rowNum}: Email '{$email}' already exists.";
                continue;
            }

            try {
                DB::transaction(function () use ($name, $email, $role, $phone, $schoolId, &$imported) {
                    $user = User::create([
                        'name'     => $name,
                        'email'    => $email,
                        // Unguessable and undelivered by design; an imported
                        // user gets in via "Forgot password". Sending a setup
                        // link per row would make a large import time out.
                        'password' => bin2hex(random_bytes(32)),
                        'must_change_password' => true,
                    ]);

                    UserProfile::create([
                        'school_id' => $schoolId,
                        'user_id'   => $user->id,
                        'role'      => $role,
                        'phone'     => $phone,
                    ]);

                    $imported++;
                });
            } catch (\Exception $e) {
                $errors[] = "Row {$rowNum}: {$e->getMessage()}";
            }
        }

        $this->logActivity($request, $request->user()->id, 'users.bulk_imported', null, [
            'imported_count' => $imported,
            'error_count' => count($errors),
        ]);

        return response()->json([
            'message'        => "Imported {$imported} users. "
                . 'They have no password yet — tell them to use "Forgot password" on the sign-in screen.',
            'imported_count' => $imported,
            'errors'         => $errors,
        ]);
    }

    public function exportUsers(Request $request)
    {
        $schoolId = $this->getSchoolId($request);

        $users = User::whereHas('userProfile', fn($q) => $q->where('school_id', $schoolId))
            ->with('userProfile')
            ->get();

        $rows = [['name', 'email', 'role', 'phone', 'status', 'created_at']];

        foreach ($users as $u) {
            $rows[] = [
                $u->name,
                $u->email,
                $u->userProfile->role ?? '',
                $u->userProfile->phone ?? '',
                $u->status ?? 'active',
                $u->created_at?->toDateTimeString(),
            ];
        }

        return response()->json([
            'status' => 'success',
            'count'  => count($rows) - 1,
            'rows'   => $rows,
        ]);
    }

    // ─── Teacher Profile ─────────────────────────────────────────────

    public function getTeacherProfile(Request $request, $userId)
    {
        $schoolId = $this->getSchoolId($request);
        $profile = TeacherProfile::where('school_id', $schoolId)->where('user_id', $userId)->with('user')->first();

        if (!$profile) {
            return response()->json(['message' => 'Teacher profile not found.'], 404);
        }

        return response()->json(['data' => $profile]);
    }

    public function updateTeacherProfile(Request $request, $userId)
    {
        $schoolId = $this->getSchoolId($request);

        $validator = Validator::make($request->all(), [
            'employee_id'        => 'nullable|string|max:50',
            'qualification'      => 'nullable|string|max:255',
            'specialization'     => 'nullable|string|max:255',
            'years_of_experience' => 'nullable|integer|min:0|max:60',
            'certifications'     => 'nullable|array',
            'subjects_taught'    => 'nullable|array',
            'performance_rating' => 'nullable|numeric|min:0|max:5',
            'date_joined'        => 'nullable|date',
            'contract_type'      => 'nullable|in:full_time,part_time,contract',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $profile = TeacherProfile::updateOrCreate(
            ['school_id' => $schoolId, 'user_id' => $userId],
            $request->only([
                'employee_id', 'qualification', 'specialization', 'years_of_experience',
                'certifications', 'subjects_taught', 'performance_rating', 'date_joined', 'contract_type',
            ])
        );

        $this->logActivity($request, $request->user()->id, 'teacher_profile.updated', $profile);

        return response()->json(['message' => 'Teacher profile updated.', 'data' => $profile]);
    }

    // ─── Parent Profile ──────────────────────────────────────────────

    public function getParentProfile(Request $request, $userId)
    {
        $schoolId = $this->getSchoolId($request);
        $profile = ParentProfile::where('school_id', $schoolId)->where('user_id', $userId)->with('user')->first();

        if (!$profile) {
            return response()->json(['message' => 'Parent profile not found.'], 404);
        }

        return response()->json(['data' => $profile]);
    }

    public function updateParentProfile(Request $request, $userId)
    {
        $schoolId = $this->getSchoolId($request);

        $validator = Validator::make($request->all(), [
            'occupation'              => 'nullable|string|max:255',
            'workplace'               => 'nullable|string|max:255',
            'relationship_to_student' => 'nullable|string|max:100',
            'alternate_phone'         => 'nullable|string|max:20',
            'alternate_email'         => 'nullable|email|max:255',
            'custody_type'            => 'nullable|in:full,joint,other',
            'emergency_priority'      => 'nullable|integer|min:1|max:10',
            'preferred_contact_method' => 'nullable|in:phone,email,sms,whatsapp',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $profile = ParentProfile::updateOrCreate(
            ['school_id' => $schoolId, 'user_id' => $userId],
            $request->only([
                'occupation', 'workplace', 'relationship_to_student', 'alternate_phone',
                'alternate_email', 'custody_type', 'emergency_priority', 'preferred_contact_method',
            ])
        );

        $this->logActivity($request, $request->user()->id, 'parent_profile.updated', $profile);

        return response()->json(['message' => 'Parent profile updated.', 'data' => $profile]);
    }

    // ─── Student Portfolio & Achievements ─────────────────────────────

    public function getPortfolio(Request $request, $studentId)
    {
        $schoolId = $this->getSchoolId($request);
        $student = Student::where('school_id', $schoolId)->findOrFail($studentId);

        // Route middleware admits parents and students generally; the policy
        // narrows it to their own child / their own record.
        $this->authorize('view', $student);

        $entries = StudentPortfolio::where('school_id', $schoolId)
            ->where('student_id', $student->id)
            ->with('verifiedBy')
            ->orderBy('date_achieved', 'desc')
            ->get();

        return response()->json(['data' => $entries]);
    }

    public function addPortfolioEntry(Request $request, $studentId)
    {
        $schoolId = $this->getSchoolId($request);
        $student = Student::where('school_id', $schoolId)->findOrFail($studentId);

        $this->authorize('update', $student);

        $validator = Validator::make($request->all(), [
            'achievement_type' => 'required|in:academic,sports,arts,leadership,community',
            'title'            => 'required|string|max:255',
            'description'      => 'nullable|string|max:2000',
            'date_achieved'    => 'nullable|date',
            'evidence_url'     => 'nullable|url|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $entry = StudentPortfolio::create([
            'school_id'        => $schoolId,
            'student_id'       => $student->id,
            'achievement_type' => $request->achievement_type,
            'title'            => $request->title,
            'description'      => $request->description,
            'date_achieved'    => $request->date_achieved,
            'evidence_url'     => $request->evidence_url,
        ]);

        $this->logActivity($request, $request->user()->id, 'portfolio.entry_added', $entry);

        return response()->json(['message' => 'Portfolio entry added.', 'data' => $entry], 201);
    }

    public function verifyPortfolioEntry(Request $request, $id)
    {
        $schoolId = $this->getSchoolId($request);
        $entry = StudentPortfolio::where('school_id', $schoolId)->findOrFail($id);

        $entry->update([
            'verified_by' => $request->user()->id,
            'verified_at' => now(),
        ]);

        $this->logActivity($request, $request->user()->id, 'portfolio.verified', $entry);

        return response()->json(['message' => 'Portfolio entry verified.', 'data' => $entry]);
    }

    // ─── Security Controls ───────────────────────────────────────────

    /**
     * Admin-initiated reset.
     *
     * This used to mint an 8-character temporary password and return it in the
     * response body for the admin to read out over the phone. That put a
     * working credential into request logs, browser history and any front-end
     * error reporting, and it meant a third party knew the password before the
     * account holder did.
     *
     * Now it sends the user the same single-use link that self-service reset
     * sends, and the admin is told it went — not what it contains. The account
     * is locked out of its old password immediately either way: the stored hash
     * is replaced with an unguessable value nobody has seen, and every session
     * is revoked.
     */
    public function resetPassword(Request $request, PasswordResetService $resets, $id)
    {
        $schoolId = $this->getSchoolId($request);
        $user = User::whereHas('userProfile', fn($q) => $q->where('school_id', $schoolId))->findOrFail($id);

        $user->update([
            'password' => bin2hex(random_bytes(32)),
            'must_change_password' => true,
            'password_changed_at' => now(),

            // The usual reason an admin reaches for this is that the user is
            // locked out, so leaving the lockout in place would defeat it.
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ]);
        $user->tokens()->delete();

        $delivery = $resets->sendSetupLink($user, School::find($schoolId), isNewAccount: false);
        $sent = ($delivery['email']['status'] ?? null) === 'sent';

        $this->logActivity($request, $request->user()->id, 'user.password_reset', $user, [
            'link_delivered' => $sent,
        ]);

        return response()->json([
            'message' => $sent
                ? 'Reset link sent to the user. Their old password and all their sessions have been revoked.'
                : 'Old password and sessions revoked, but the reset link could not be delivered. Ask the user to use "Forgot password" on the sign-in screen.',
            'link_sent' => $sent,
        ]);
    }

    public function unlockAccount(Request $request, $id)
    {
        $schoolId = $this->getSchoolId($request);
        $user = User::whereHas('userProfile', fn($q) => $q->where('school_id', $schoolId))->findOrFail($id);

        $user->update([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'status' => 'active',
        ]);

        $this->logActivity($request, $request->user()->id, 'user.account_unlocked', $user);

        return response()->json(['message' => 'Account unlocked and reactivated.']);
    }

    public function revokeAllSessions(Request $request, $id)
    {
        $schoolId = $this->getSchoolId($request);
        $user = User::whereHas('userProfile', fn($q) => $q->where('school_id', $schoolId))->findOrFail($id);

        $count = $user->tokens()->count();
        $user->tokens()->delete();

        $this->logActivity($request, $request->user()->id, 'user.sessions_revoked', $user, [
            'sessions_revoked' => $count,
        ]);

        return response()->json([
            'message' => "Revoked {$count} active sessions.",
            'revoked_count' => $count,
        ]);
    }

    public function getSecurityOverview(Request $request, $id)
    {
        $schoolId = $this->getSchoolId($request);
        $user = User::whereHas('userProfile', fn($q) => $q->where('school_id', $schoolId))
            ->with('userProfile')
            ->findOrFail($id);

        return response()->json([
            'two_factor_enabled'   => (bool) ($user->userProfile->two_factor_enabled ?? false),
            'two_factor_method'    => $user->userProfile->two_factor_method ?? null,
            'last_login_at'        => $user->last_login_at,
            'failed_login_attempts' => $user->failed_login_attempts,
            'locked_until'         => $user->locked_until,
            'password_changed_at'  => $user->password_changed_at,
            'must_change_password' => $user->must_change_password,
            'active_sessions'      => $user->tokens()->count(),
            'account_status'       => $user->status,
            'email_verified'       => $user->email_verified_at !== null,
        ]);
    }
}
