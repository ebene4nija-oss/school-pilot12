<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Telling a parent their child was marked absent — only when both the school
 * and the parent have asked for it.
 *
 * Off by default at both levels. The school switches it on (and decides
 * whether it may cost SMS, which it pays for per message); then each parent
 * opts in per child, from the app or by asking the school office.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->boolean('absence_alerts_enabled')->default(false);
            // Push is free; SMS is billed to the school per message.
            $table->boolean('absence_alerts_sms')->default(false);
        });

        Schema::table('student_guardian', function (Blueprint $table) {
            $table->boolean('absence_alerts')->default(false);
            $table->timestamp('absence_alerts_set_at')->nullable();
        });

        // One alert per child, per parent, per day — however often the
        // register is resubmitted or corrected.
        Schema::create('absence_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->onDelete('cascade');
            $table->foreignId('student_id')->constrained()->onDelete('cascade');
            $table->foreignId('guardian_user_id')->constrained('users')->onDelete('cascade');
            $table->date('date');
            $table->string('channels', 64)->nullable();
            $table->string('outcome', 16); // sent | failed
            $table->timestamps();

            $table->unique(['student_id', 'guardian_user_id', 'date']);
            $table->index(['school_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('absence_alerts');

        Schema::table('student_guardian', function (Blueprint $table) {
            $table->dropColumn(['absence_alerts', 'absence_alerts_set_at']);
        });

        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn(['absence_alerts_enabled', 'absence_alerts_sms']);
        });
    }
};
