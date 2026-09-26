<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A school's own grading scale, per class level.
 *
 * Grades were the WAEC A1–F9 ladder, hardcoded in four places, whatever the
 * school printed on its report card. Many schools grade primary pupils A–E and
 * senior secondary A1–F9; `level` lets one school hold both. `default` applies
 * to any class whose level has no scale of its own. No row at all means WAEC.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grading_scales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            // default | nursery | primary | junior_secondary | senior_secondary
            $table->string('level', 30)->default('default');
            $table->string('name')->nullable();
            // [{ "min": 75, "max": 100, "grade": "A1", "remark": "Excellent" }, …]
            $table->json('bands');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['school_id', 'level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grading_scales');
    }
};
