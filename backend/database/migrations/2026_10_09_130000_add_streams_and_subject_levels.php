<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Streams, merit arms and subjects that belong to a level.
 *
 * A Nigerian SS class is usually split by stream: SS 2A takes Physics and
 * Chemistry, SS 2B takes Literature and Government. Some schools also keep
 * their strongest students together in one arm. Before this, an arm was only
 * a name and a class offered one subject list to every arm, so a science arm
 * and an arts arm could not be told apart anywhere.
 *
 *  - arms.stream: science | commercial | arts | technical; null = general or
 *    mixed (students then pick stream subjects one by one).
 *  - arms.is_merit: the school's "best students" arm. Staff-only.
 *  - class_subjects.streams: which streams take the subject; null = all.
 *  - subjects.level_groups: which levels the subject is taught at; null = all.
 *    Keeps Physics out of the Nursery 1 picker.
 *  - Positions within the class, the arm or the stream. Schools that existed
 *    before this keep whole-class ranking; new schools rank within the arm.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('arms', function (Blueprint $table) {
            $table->string('stream', 20)->nullable()->after('name');
            $table->boolean('is_merit')->default(false)->after('stream');
        });

        Schema::table('class_subjects', function (Blueprint $table) {
            $table->json('streams')->nullable()->after('is_compulsory');
        });

        Schema::table('subjects', function (Blueprint $table) {
            $table->json('level_groups')->nullable()->after('department');
        });

        Schema::table('broadsheet_configurations', function (Blueprint $table) {
            // class | arm | stream; null = the school's default
            $table->string('ranking_group', 10)->nullable()->after('ranking_scope');
        });

        Schema::table('schools', function (Blueprint $table) {
            $table->string('default_ranking_group', 10)->default('arm');
        });

        // Every school that exists now has been ranking across the whole class.
        DB::table('schools')->update(['default_ranking_group' => 'class']);
    }

    public function down(): void
    {
        Schema::table('schools', fn (Blueprint $table) => $table->dropColumn('default_ranking_group'));
        Schema::table('broadsheet_configurations', fn (Blueprint $table) => $table->dropColumn('ranking_group'));
        Schema::table('subjects', fn (Blueprint $table) => $table->dropColumn('level_groups'));
        Schema::table('class_subjects', fn (Blueprint $table) => $table->dropColumn('streams'));
        Schema::table('arms', fn (Blueprint $table) => $table->dropColumn(['stream', 'is_merit']));
    }
};
