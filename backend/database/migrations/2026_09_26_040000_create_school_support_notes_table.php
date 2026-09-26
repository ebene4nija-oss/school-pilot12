<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An operator's running notes on a school: calls taken, special arrangements,
 * what was promised on WhatsApp. SchoolPilot's own record, never shown to the
 * school it is about.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_support_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->text('body');
            $table->timestamps();

            $table->index(['school_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_support_notes');
    }
};
