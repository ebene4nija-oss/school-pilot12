<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A school asking SchoolPilot to bring its old records across.
 *
 * Switching from paper, a spreadsheet or a rival portal is the single biggest
 * obstacle to a school adopting this platform, and the offer to do it for them
 * is a real commitment with a deadline attached. The portal advertised it with a
 * form that resolved a `setTimeout` and told the school "successfully queued" —
 * so a proprietor who accepted the offer was told it had been actioned while
 * nothing was recorded anywhere and nobody was told.
 *
 * The bulk CSV importer is the self-service path and is unaffected. This is the
 * assisted one: a request an operator can see, work and close.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_migration_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->onDelete('cascade');
            $table->foreignId('requested_by')->constrained('users')->onDelete('cascade');

            $table->string('previous_system');
            $table->enum('source_format', ['excel', 'sql_dump', 'competitor_portal', 'paper', 'other'])
                ->default('excel');
            $table->text('notes')->nullable();
            $table->unsignedInteger('approximate_records')->nullable();

            $table->enum('status', ['submitted', 'in_progress', 'completed', 'cancelled'])
                ->default('submitted');
            // What the school was promised, so a missed deadline is visible
            // rather than a matter of recollection.
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('operator_notes')->nullable();

            $table->timestamps();

            $table->index(['school_id', 'status']);
            $table->index(['status', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_migration_requests');
    }
};
