<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Let a user exist without an email address.
 *
 * Many parents here have a phone and no email, and most primary pupils have
 * neither. Because `users.email` was required, the student importer invented
 * one for everybody without — `guardian-08031234567@greenfield.local`,
 * `2025-0001@greenfield.local` — and those placeholders now matter: email is a
 * broadcast channel, so a message to "all parents" would try to mail every one
 * of them. `.local` never delivers, and a burst of bounces damages the shared
 * sending domain for every school on the platform.
 *
 * Sign-in is unchanged. A user without an email cannot sign in with a password
 * (the login lookup is by email) until the school adds one; they can still be
 * enrolled, linked to their children and reached by SMS.
 *
 * The unique index stays: two users can never share an address, and any number
 * may have none — Postgres and SQLite both treat NULLs as distinct.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });

        /*
         * Clear the invented addresses. `.local` is reserved for multicast DNS
         * on a local link (RFC 6762): no public mail server accepts it, so an
         * address ending in it was never a real one.
         */
        DB::table('users')->where('email', 'like', '%.local')->update(['email' => null]);
    }

    public function down(): void
    {
        // Cannot restore the invented addresses, and there is no need to: give
        // any address-less user a unique non-routable placeholder so NOT NULL
        // can be reapplied.
        DB::table('users')->whereNull('email')->orderBy('id')->each(function ($user) {
            DB::table('users')->where('id', $user->id)->update(['email' => "user-{$user->id}@placeholder.local"]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
        });
    }
};
