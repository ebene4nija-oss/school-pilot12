<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-wide settings the operator manages from the console.
 *
 * Until now the platform's integrations — the Anthropic key, the SMS and
 * WhatsApp provider keys, the push credentials, the AI spend cap — could only
 * be changed by editing backend.env over SSH and re-caching config. That put
 * rotating a leaked key, or raising a cap during exam week, behind a server
 * login.
 *
 * Every value is encrypted with APP_KEY, secrets and plain settings alike: it
 * costs nothing, and it means a database dump — which is exactly what a backup
 * is — does not carry the keys in the clear. The consequence to know about is
 * the one APP_KEY already carries: rotate it and these become unreadable, and
 * the platform falls back to whatever backend.env holds.
 *
 * Rows only ever hold a known key. PlatformSettings defines which keys exist;
 * the console cannot invent new ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
