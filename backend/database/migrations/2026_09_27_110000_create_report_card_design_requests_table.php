<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A school asking SchoolPilot to design its report card.
 *
 * The school sends what it has (a sample card, its crest, signatures) and fills
 * in a structured brief; SchoolPilot builds the template, uploads it against
 * the request, and the school previews it and approves — only then does it go
 * live. Files are deleted once the request is finished (`files_purged_at`): a
 * sample report card can carry a real child's name and marks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_card_design_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            // submitted → in_progress → template_ready → approved
            //                         ↘ changes_requested ↗        (or cancelled)
            $table->string('status', 30)->default('submitted');
            // The brief: sections, CA split, remarks, traits, paper, notes.
            $table->json('requirements');
            // Grading scales the school asked for, per level, in the same
            // band format as grading_scales.bands. Applied on approval.
            $table->json('grading_scales')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('operator_notes')->nullable();
            $table->text('school_feedback')->nullable();
            $table->foreignId('template_id')->nullable()->constrained('report_card_templates')->nullOnDelete();
            $table->timestamp('template_uploaded_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('files_purged_at')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'status']);
            $table->index('status');
        });

        Schema::create('report_card_design_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('report_card_design_requests')->cascadeOnDelete();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            // sample | crest | signature | other
            $table->string('kind', 20);
            $table->string('label')->nullable();
            $table->string('original_name');
            $table->string('path');
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_card_design_files');
        Schema::dropIfExists('report_card_design_requests');
    }
};
