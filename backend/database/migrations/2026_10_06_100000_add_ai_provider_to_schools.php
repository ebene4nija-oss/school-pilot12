<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which AI provider a school's AI features use: anthropic, openai or gemini.
 * Null means SchoolPilot's default (services.ai.provider). A school's own
 * settings, not personal data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->string('ai_provider', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn('ai_provider');
        });
    }
};
