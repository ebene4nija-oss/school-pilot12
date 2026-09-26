<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Encrypt `schools.ai_api_settings` in place.
 *
 * It holds a school's own AI provider key and was stored as plain JSON, so a
 * database dump or a stray SELECT showed it. The model now casts it as
 * `encrypted:array`; this converts the rows already there. Idempotent: a value
 * that already decrypts is left alone, so a re-run cannot double-encrypt.
 *
 * The column type is unchanged (json on Postgres accepts the ciphertext as a
 * JSON string only if quoted, so the column is widened to text first).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE schools ALTER COLUMN ai_api_settings TYPE text USING ai_api_settings::text');
        }

        DB::table('schools')->whereNotNull('ai_api_settings')->orderBy('id')->each(function ($row) {
            $raw = (string) $row->ai_api_settings;

            if ($raw === '' || $this->isEncrypted($raw)) {
                return;
            }

            DB::table('schools')->where('id', $row->id)->update([
                'ai_api_settings' => Crypt::encryptString($raw),
            ]);
        });
    }

    public function down(): void
    {
        DB::table('schools')->whereNotNull('ai_api_settings')->orderBy('id')->each(function ($row) {
            $raw = (string) $row->ai_api_settings;

            if ($this->isEncrypted($raw)) {
                DB::table('schools')->where('id', $row->id)->update(['ai_api_settings' => Crypt::decryptString($raw)]);
            }
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE schools ALTER COLUMN ai_api_settings TYPE json USING ai_api_settings::json');
        }
    }

    private function isEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
};
