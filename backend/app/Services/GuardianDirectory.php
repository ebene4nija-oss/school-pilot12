<?php

namespace App\Services;

use App\Models\Guardian;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Validation\ValidationException;

/**
 * Finding or creating a child's parent, one account per family.
 *
 * Used by both the enrolment form and the CSV importer, which previously did
 * this separately — the form not at all — so a second child enrolled by hand
 * created a second account for the same mother.
 *
 * A family is recognised by phone number, the one thing every parent here has.
 * Numbers are compared in every form they are written: 0803…, 803…, 234803…,
 * +234803… are one number.
 */
class GuardianDirectory
{
    /**
     * @param  array{name: string, phone?: ?string, email?: ?string, relationship?: ?string, occupation?: ?string}  $details
     * @return array{guardian: Guardian, created: bool}
     */
    public function findOrCreate(int $schoolId, array $details): array
    {
        $phone = static::canonicalPhone($details['phone'] ?? null);
        $email = filled($details['email'] ?? null) ? strtolower(trim($details['email'])) : null;

        // Same phone in this school: the same parent, whatever name was typed.
        if ($phone && ($existing = $this->byPhone($schoolId, $phone))) {
            if ($email && ! $existing->user->email) {
                $this->assertEmailFree($email, $existing->user_id);
                $existing->user->update(['email' => $email]);
            }

            return ['guardian' => $existing, 'created' => false];
        }

        if ($email && ($user = User::where('email', $email)->first())) {
            $guardian = Guardian::withoutGlobalScopes()
                ->where('school_id', $schoolId)
                ->where('user_id', $user->id)
                ->first();

            if ($guardian) {
                return ['guardian' => $guardian, 'created' => false];
            }

            /*
             * The address belongs to someone who is not a parent at this
             * school — a teacher, a student, a parent elsewhere. Attaching this
             * child to that account would hand a stranger the child's record.
             */
            throw ValidationException::withMessages([
                'guardian_email' => 'That email already belongs to another account. Use a different email or leave it empty.',
            ]);
        }

        $user = User::create([
            'name' => trim($details['name']),
            'email' => $email,
            // Random, never shown: the parent sets a password from a setup link
            // once they have an email address.
            'password' => bin2hex(random_bytes(32)),
        ]);

        UserProfile::create([
            'school_id' => $schoolId,
            'user_id' => $user->id,
            'role' => 'parent',
            'phone' => $phone,
        ]);

        $guardian = Guardian::create([
            'school_id' => $schoolId,
            'user_id' => $user->id,
            'relationship' => $details['relationship'] ?: 'parent',
            'occupation' => $details['occupation'] ?? null,
        ]);

        return ['guardian' => $guardian, 'created' => true];
    }

    /** 0803 123 4567, +234803… and 234803… stored the same way: 08031234567. */
    public static function canonicalPhone(?string $phone): ?string
    {
        if (! filled($phone)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '234') && strlen($digits) === 13) {
            return '0'.substr($digits, 3);
        }

        if (strlen($digits) === 10 && ! str_starts_with($digits, '0')) {
            return '0'.$digits;
        }

        return $digits !== '' ? $digits : null;
    }

    private function byPhone(int $schoolId, string $canonical): ?Guardian
    {
        // Rows written before numbers were canonicalised may hold any of these.
        $rest = ltrim($canonical, '0');
        $variants = array_values(array_unique([$canonical, $rest, '234'.$rest, '+234'.$rest]));

        return Guardian::withoutGlobalScopes()
            ->with('user')
            ->where('school_id', $schoolId)
            ->whereHas('user.userProfile', fn ($q) => $q->whereIn('phone', $variants))
            ->first();
    }

    private function assertEmailFree(string $email, int $exceptUserId): void
    {
        if (User::where('email', $email)->where('id', '!=', $exceptUserId)->exists()) {
            throw ValidationException::withMessages([
                'guardian_email' => 'That email already belongs to another account.',
            ]);
        }
    }
}
