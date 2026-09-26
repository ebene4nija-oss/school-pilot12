<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which optional modules SchoolPilot has switched on for a school.
 *
 * Null, or a module missing from the map, means on: every existing school
 * keeps everything it has today. Set by the operator; see SchoolFeatures.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            if (! Schema::hasColumn('schools', 'module_switches')) {
                $table->json('module_switches')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            if (Schema::hasColumn('schools', 'module_switches')) {
                $table->dropColumn('module_switches');
            }
        });
    }
};
