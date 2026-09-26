<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\UserProfile;
use App\Services\GuardianDirectory;
use Illuminate\Http\Request;

/**
 * A user changing their own phone number and photo (mobile gap G3).
 *
 * Only an admin could edit a profile, so a parent who changed SIM had to ask
 * the school to stop texting the old number. Two limits:
 *
 * - The phone number is how the school recognises a family (GuardianDirectory
 *   matches parents by it) and where SMS goes, so it is normalised the same
 *   way and refused if someone else at the school already has it — otherwise
 *   the next enrolment would attach one family's child to another.
 * - The photo is the account's own picture. A student's official passport
 *   photo, printed on ID cards and report cards, is `students.passport_photo_path`
 *   and stays the school's to set.
 */
class SelfProfileController extends Controller
{
    public function update(Request $request)
    {
        $validated = $request->validate([
            'phone' => 'sometimes|nullable|string|max:20',
            // A picture, small enough for a metered connection.
            'photo' => 'sometimes|file|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        $user = $request->user();
        $profile = $user->userProfile;

        if (! $profile) {
            return response()->json(['message' => 'This account has no profile.'], 422);
        }

        $changed = [];

        if (array_key_exists('phone', $validated)) {
            $phone = GuardianDirectory::canonicalPhone($validated['phone']);

            if (filled($validated['phone']) && ! $phone) {
                return response()->json(['errors' => ['phone' => ['That is not a phone number we recognise.']]], 422);
            }

            if ($phone && $phone !== $profile->phone) {
                // Rows written before numbers were canonicalised may hold any
                // of these forms of the same number.
                $rest = ltrim($phone, '0');
                $variants = [$phone, $rest, '234'.$rest, '+234'.$rest];

                $taken = UserProfile::query()
                    ->where('school_id', $profile->school_id)
                    ->where('user_id', '!=', $user->id)
                    ->whereIn('phone', $variants)
                    ->exists();

                if ($taken) {
                    return response()->json([
                        'errors' => ['phone' => ['Someone else at your school already uses that number. Ask the school office to help.']],
                    ], 422);
                }
            }

            if ($phone !== $profile->phone) {
                $profile->phone = $phone;
                $changed[] = 'phone';
            }
        }

        if ($request->hasFile('photo')) {
            $profile->avatar_url = $request->file('photo')->store('avatars', 'public');
            $changed[] = 'photo';
        }

        if ($changed !== []) {
            $profile->save();

            // Which fields, not their values: a phone number is personal data.
            AuditLog::create([
                'school_id' => $profile->school_id,
                'user_id' => $user->id,
                'action' => 'profile.self_updated',
                'auditable_type' => UserProfile::class,
                'auditable_id' => $profile->id,
                'new_values' => ['fields' => $changed],
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
            ]);
        }

        return response()->json([
            'message' => $changed === [] ? 'Nothing changed.' : 'Your profile has been updated.',
            'data' => [
                'phone' => $profile->phone,
                'avatar_url' => $profile->avatar_url,
            ],
        ]);
    }
}
