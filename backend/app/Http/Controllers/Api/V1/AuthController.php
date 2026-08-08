<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\School;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\TotpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
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

        /*
         * Tenant binding.
         *
         * The tenant is taken from the resolved host first (set by
         * TenantResolutionMiddleware) and only then from the request body.
         * Previously the body was the sole source and was optional, so a user
         * from one school could authenticate against another school's
         * subdomain — the middleware resolved a tenant that nothing enforced.
         */
        $tenant = $request->attributes->get('tenant_school');

        if (!$tenant && $request->subdomain) {
            $tenant = School::where('subdomain', $request->subdomain)->first();

            if (!$tenant) {
                return response()->json(['message' => 'School tenant subdomain not found.'], 404);
            }
        }

        if ($tenant && $profile && $profile->role !== 'super_admin' && $profile->school_id !== $tenant->id) {
            AuditLog::create([
                'school_id' => $tenant->id,
                'user_id' => $user->id,
                'action' => 'user.login_wrong_tenant',
                'auditable_type' => User::class,
                'auditable_id' => $user->id,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json(['message' => 'User does not belong to this school tenant.'], 403);
        }

        // Two-factor for privileged roles.
        if ($profile && in_array($profile->role, ['school_admin', 'super_admin']) && $profile->two_factor_enabled) {
            if (!$request->two_factor_code) {
                return response()->json([
                    'message' => 'Two-factor authentication code required.',
                    'requires_2fa' => true
                ], 422);
            }

            $secret = (string) ($profile->two_factor_secret ?? '');

            // Fail closed. An account flagged for 2FA without a stored secret is
            // misconfigured, not exempt — there is no fallback secret.
            if (trim($secret) === '') {
                Log::warning('2FA is enabled without a stored secret.', [
                    'user_id' => $user->id,
                    'school_id' => $profile->school_id,
                ]);

                return response()->json([
                    'message' => 'Two-factor authentication is not fully set up on this account. Contact your administrator.',
                ], 422);
            }

            if (!app(TotpService::class)->verifyCode($secret, (string) $request->two_factor_code)) {
                return response()->json(['message' => 'Invalid 2FA code.'], 422);
            }
        }

        $token = $user->createToken('schoolpilot-auth-token')->plainTextToken;

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

        $tempPassword = bin2hex(random_bytes(16));

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

        /*
         * The temporary password is deliberately not returned. It used to come
         * back in this response body, which put a working credential into
         * request logs, browser history and any client-side error reporting.
         *
         * TODO(delivery): send the credential over the school's configured
         * channel (email/SMS) as a single-use, expiring setup link. Until that
         * job exists, an admin triggers /users/{id}/reset-password to hand the
         * user a fresh one out of band.
         */
        return response()->json([
            'message' => 'User invited successfully. Send them a password-setup link to complete onboarding.',
            'user_id' => $user->id,
        ], 201);
    }
}
