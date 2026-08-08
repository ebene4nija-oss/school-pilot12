<?php

namespace App\Services;

class TotpService
{
    /**
     * Generate a secret key for TOTP authentication
     */
    public function generateSecret(int $length = 16): string
    {
        $b32 = '234567QWERTYUIOPASDFGHJKLZXCVBNM';
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= $b32[random_int(0, 31)];
        }
        return $secret;
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
        if (empty($secret)) return '';
        $b32 = '234567QWERTYUIOPASDFGHJKLZXCVBNM';
        $b32lookup = array_flip(str_split($b32));
        $secret = strtoupper($secret);
        $l = strlen($secret);
        $n = 0;
        $j = 0;
        $binary = '';
        for ($i = 0; $i < $l; $i++) {
            $n = $n << 5;
            $n = $n | $b32lookup[$secret[$i]];
            $j += 5;
            if ($j >= 8) {
                $j -= 8;
                $binary .= chr(($n & (0xFF << $j)) >> $j);
            }
        }
        return $binary;
    }
}
