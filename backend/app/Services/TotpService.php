<?php

namespace App\Services;

/**
 * TOTP (RFC 6238) for admin two-factor authentication.
 *
 * The base32 alphabet below is the RFC 4648 one. It previously was not — it was
 * `234567QWERTYUIOPASDFGHJKLZXCVBNM`, a scrambled variant. Nothing caught it
 * because the only caller generated a secret here and verified it here, so the
 * codes agreed with themselves. They would not have agreed with Google
 * Authenticator, Authy, or any other app, all of which decode per RFC 4648: an
 * admin scanning the enrolment QR would have produced six digits that never
 * matched, with no way to tell why. `test_rfc6238_known_answer` pins this
 * against the published test vector so the alphabet cannot drift again.
 */
class TotpService
{
    /** RFC 4648 §6. Every authenticator app assumes exactly this ordering. */
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Generate a secret key for TOTP authentication.
     *
     * 16 characters of base32 is 80 bits. RFC 4226 §4 requires at least 128
     * bits for the shared secret, so the default is 32 characters (160 bits),
     * which is also what Google Authenticator's own provisioning emits.
     */
    public function generateSecret(int $length = 32): string
    {
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= self::BASE32_ALPHABET[random_int(0, 31)];
        }
        return $secret;
    }

    /**
     * The `otpauth://` URI an authenticator app scans.
     *
     * The issuer appears twice — once as a label prefix and once as a parameter
     * — because older apps read only the prefix and newer ones only the
     * parameter. Both spellings are in Google's Key URI Format spec.
     */
    public function provisioningUri(string $secret, string $accountName, string $issuer): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($accountName);

        return 'otpauth://totp/' . $label . '?' . http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => 6,
            'period' => 30,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Verify a 6-digit TOTP code against a secret (Time-based One Time Password RFC 6238)
     */
    public function verifyCode(string $secret, string $code, int $discrepancy = 1, ?int $timeSlice = null): bool
    {
        if (strlen($code) !== 6 || !ctype_digit($code)) {
            return false;
        }

        // An empty secret can never be satisfied. Previously the caller supplied
        // a well-known fallback secret here, which made 2FA bypassable for any
        // account that had it enabled but never completed enrolment.
        if (trim($secret) === '') {
            return false;
        }

        if ($timeSlice === null) {
            $timeSlice = (int) floor(time() / 30);
        }

        for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
            $calculatedCode = $this->calculateCode($secret, $timeSlice + $i);
            if (hash_equals($calculatedCode, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Current 6-digit code for a secret. Used when provisioning an
     * authenticator and by the test suite; never used to verify input.
     */
    public function generateCode(string $secret, ?int $timeSlice = null): string
    {
        return $this->calculateCode($secret, $timeSlice ?? (int) floor(time() / 30));
    }

    /**
     * Calculate 6-digit TOTP code for a secret and time slice
     */
    private function calculateCode(string $secret, int $timeSlice): string
    {
        $secretKey = $this->base32Decode($secret);
        $time = pack('N*', 0) . pack('N*', $timeSlice);
        $hmac = hash_hmac('sha1', $time, $secretKey, true);
        $offset = ord(substr($hmac, -1)) & 0x0F;
        $hashpart = substr($hmac, $offset, 4);
        $value = unpack('N', $hashpart);
        $value = $value[1] & 0x7FFFFFFF;
        $modulo = pow(10, 6);
        return str_pad((string)($value % $modulo), 6, '0', STR_PAD_LEFT);
    }

    private function base32Decode(string $secret): string
    {
        // Users paste secrets out of password managers, which add spaces and
        // '=' padding. Both are meaningless to the decode and must be dropped
        // rather than looked up — an unknown character used to index the
        // lookup table and emit a silent null byte.
        $secret = strtoupper(str_replace([' ', '-', '='], '', $secret));

        if ($secret === '') {
            return '';
        }

        $lookup = array_flip(str_split(self::BASE32_ALPHABET));

        $n = 0;
        $j = 0;
        $binary = '';

        for ($i = 0, $l = strlen($secret); $i < $l; $i++) {
            if (! isset($lookup[$secret[$i]])) {
                // Not base32 at all. Return nothing so verifyCode fails closed
                // instead of comparing against a partially decoded key.
                return '';
            }

            $n = ($n << 5) | $lookup[$secret[$i]];
            $j += 5;

            if ($j >= 8) {
                $j -= 8;
                $binary .= chr(($n & (0xFF << $j)) >> $j);
            }
        }

        return $binary;
    }
}
