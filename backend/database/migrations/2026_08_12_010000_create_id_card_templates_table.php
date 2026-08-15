<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-school ID card designs.
 *
 * Deliberately a sibling of `report_card_templates` rather than a second use
 * of it. The two documents share a rendering pipeline (the same restricted
 * dialect, the same sanitiser) but nothing else: a report card is one A4 page
 * of tabular data, an ID card is a two-sided 85.6x54mm object that gets
 * imposed many-to-a-sheet. They validate against different required tokens and
 * carry different geometry, and folding both into one table would mean a
 * `type` column that half the columns are null for.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('id_card_templates')) {
            return;
        }

        Schema::create('id_card_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('slug');
            $table->unsignedInteger('version')->default(1);
            $table->text('description')->nullable();
            $table->string('engine')->default('schoolpilot-id-card/v1');

            /*
             * Which holders this design is for. A school usually wants one
             * design for children and a visibly different one for staff, so
             * the active-design constraint is per holder type, not per school.
             */
            $table->string('holder_type')->default('student'); // student | staff

            $table->longText('front');           // template markup, card face
            $table->longText('back')->nullable(); // omitted for single-sided runs
            $table->longText('styles')->nullable();

            /*
             * { "size": "CR80", "width_mm": 85.6, "height_mm": 54,
             *   "orientation": "landscape", "bleed_mm": 0, "corner_radius_mm": 3 }
             *
             * Stored resolved rather than as a named size so a card printed in
             * 2026 still measures the same if the named-size table ever moves.
             */
            $table->json('card_geometry')->nullable();

            $table->json('regions')->nullable();
            $table->json('validation_report')->nullable();

            $table->string('checksum', 64);
            $table->string('status')->default('draft'); // draft | active | archived
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'slug', 'version']);
            $table->index(['school_id', 'holder_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('id_card_templates');
    }
};