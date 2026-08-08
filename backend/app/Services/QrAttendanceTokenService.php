<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Issues and verifies the short-lived tokens behind hardware-free QR attendance.
 *
 * The token is an opaque, single-use handle: the claims live server-side in the
 * cache and the client only ever holds a random id plus an HMAC over it. That
 * means a forged or replayed token cannot be constructed offline, and marking
 * attendance twice with the same scan is rejected rather than silently updated.
 */
class QrAttendanceTokenService
{
    public const TTL_SECONDS = 900; // 15 minutes

    private const CACHE_PREFIX = 'qr_attendance:';

    public function issue(int $schoolId, int $classId, int $termId): string
    {
        $id = Str::random(32);

        Cache::put(self::CACHE_PREFIX . $id, [
            'school_id' => $schoolId,
            'class_id' => $classId,
            'term_id' => $termId,
        ], self::TTL_SECONDS);

        return $id . '.' . $this->sign($id);
    }

    /**
     * @return array{school_id:int,class_id:int,term_id:int}|null
     *         Null when the token is malformed, unsigned, expired, or already spent.
     */
    public function verify(string $token): ?array
    {
        if (! str_contains($token, '.')) {
            return null;
        }

        [$id, $signature] = explode('.', $token, 2);

        if ($id === '' || $signature === '') {
            return null;
        }

        // Constant-time comparison — a byte-by-byte early exit leaks the signature.
        if (! hash_equals($this->sign($id), $signature)) {
            return null;
        }

        $claims = Cache::get(self::CACHE_PREFIX . $id);

        if (! is_array($claims)) {
            return null;
        }

        foreach (['school_id', 'class_id', 'term_id'] as $required) {
            if (! array_key_exists($required, $claims)) {
                return null;
            }
        }

        return $claims;
    }

    /**
     * Spend the token so the same scan cannot be replayed.
     */
    public function consume(string $token): void
    {
        if (! str_contains($token, '.')) {
            return;
        }

        [$id] = explode('.', $token, 2);
        Cache::forget(self::CACHE_PREFIX . $id);
    }

    private function sign(string $id): string
    {
        return hash_hmac('sha256', $id, config('app.key'));
    }
}
