<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\School;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required',
            'subdomain' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $profile = $user->userProfile;

        // Ensure user belongs to requested tenant subdomain if provided
        if ($request->subdomain && $profile && $profile->role !== 'super_admin') {
            $school = School::where('subdomain', $request->subdomain)->first();
            if (!$school || $profile->school_id !== $school->id) {
                return response()->json(['message' => 'User does not belong to this school tenant.'], 403);
            }
        }

        // Check if TOTP 2FA is required for School Admin / Super Admin
        if ($profile && in_array($profile->role, ['school_admin', 'super_admin']) && $profile->two_factor_enabled) {
            if (!$request->two_factor_code) {
                return response()->json([
                    'message' => 'Two-factor authentication code required.',
                    'requires_2fa' => true
                ], 422);
            }

            // Verify TOTP 2FA code via TotpService RFC 6238
            $totpService = app(\App\Services\TotpService::class);
            $secret = $profile->two_factor_secret ?? 'JBSWY3DPEHPK3PXP'; // Fallback secret
            if (!$totpService->verifyCode($secret, $request->two_factor_code)) {
                return response()->json(['message' => 'Invalid 2FA code.'], 422);
            }
        }

        // Issue Sanctum Token
        $token = $user->createToken('schoolpilot-auth-token')->plainTextToken;

        // Log audit entry
        AuditLog::create([
            'school_id' => $profile ? $profile->school_id : null,
            'user_id' => $user->id,
            'action' => 'user.login',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'Login successful',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $profile ? $profile->role : 'student',
                'school_id' => $profile ? $profile->school_id : null,
            ]
        ]);
    }

    public function inviteUser(Request $request)
    {
        $admin = $request->user();
        $profile = $admin->userProfile;

        if (!$profile || !in_array($profile->role, ['super_admin', 'school_admin'])) {
            return response()->json(['message' => 'Unauthorized to invite users.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'role' => 'required|in:school_admin,teacher,student,parent',
            'phone' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Temporary default password for invited user
        $tempPassword = bin2hex(random_bytes(4));

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($tempPassword),
        ]);

        UserProfile::create([
            'school_id' => $profile->school_id,
            'user_id' => $user->id,
            'role' => $request->role,
            'phone' => $request->phone,
        ]);

        // Audit Log
        AuditLog::create([
            'school_id' => $profile->school_id,
            'user_id' => $admin->id,
            'action' => 'user.invited',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'new_values' => ['email' => $user->email, 'role' => $request->role],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'User invited successfully',
            'user_id' => $user->id,
            'temp_password' => $tempPassword,
        ], 201);
    }
}
