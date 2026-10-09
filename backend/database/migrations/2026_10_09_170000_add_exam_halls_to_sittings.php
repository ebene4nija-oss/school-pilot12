<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a paper is written.
 *
 * Everyone taking a subject sits its paper together: SS 2 Physics is the
 * science students of every arm, gathered in the exam hall, or each arm in
 * its own classroom. The school says which per paper.
 *
 *  - venue_mode: hall (one place for every candidate) or own_classrooms.
 *  - venue_id: the hall, from the school's venues; `venue` stays for a place
 *    typed in by name, and for every sitting saved before this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_sittings', function (Blueprint $table) {
            $table->string('venue_mode', 20)->default('hall')->after('end_time');
            $table->foreignId('venue_id')->nullable()->after('venue_mode')->constrained('venues')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('exam_sittings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('venue_id');
            $table->dropColumn('venue_mode');
        });
    }
};
