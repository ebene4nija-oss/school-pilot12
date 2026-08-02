<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            if (!Schema::hasColumn('schools', 'ai_enabled')) {
                $table->boolean('ai_enabled')->default(true)->after('status');
            }
            if (!Schema::hasColumn('schools', 'ai_feature_flags')) {
                $table->json('ai_feature_flags')->nullable()->after('ai_enabled');
            }
            if (!Schema::hasColumn('schools', 'ai_api_settings')) {
                $table->json('ai_api_settings')->nullable()->after('ai_feature_flags');
            }
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn(['ai_enabled', 'ai_feature_flags', 'ai_api_settings']);
        });
    }
};
