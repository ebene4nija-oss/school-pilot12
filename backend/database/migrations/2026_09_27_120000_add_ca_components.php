<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Any number of continuous assessments, not just two.
 *
 * `ca_schemes.components` lists a school's parts in order, e.g.
 * `[{key: ca1, name: "1st CA", max: 10, kind: ca}, …, {key: exam, …}]`.
 * Null means the classic shape read from the three weight columns.
 *
 * `score_entries.component_scores` holds a mark per part. The three legacy
 * columns are still filled (first CA, the other CAs summed, the exam), so the
 * broadsheet, the mobile app and existing report-card templates keep working
 * unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ca_schemes', function (Blueprint $table) {
            if (! Schema::hasColumn('ca_schemes', 'components')) {
                $table->json('components')->nullable();
            }
        });

        Schema::table('score_entries', function (Blueprint $table) {
            if (! Schema::hasColumn('score_entries', 'component_scores')) {
                $table->json('component_scores')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('score_entries', function (Blueprint $table) {
            if (Schema::hasColumn('score_entries', 'component_scores')) {
                $table->dropColumn('component_scores');
            }
        });

        Schema::table('ca_schemes', function (Blueprint $table) {
            if (Schema::hasColumn('ca_schemes', 'components')) {
                $table->dropColumn('components');
            }
        });
    }
};
