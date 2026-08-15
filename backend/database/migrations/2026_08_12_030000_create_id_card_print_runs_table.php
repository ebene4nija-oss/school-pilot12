<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One batch of cards sent to a printer.
 *
 * A school does not print one ID card; it prints JSS 2 Gold, or the whole of
 * SS, or every member of staff. That run is a thing with its own life: it is
 * queued, it takes minutes, it half-fails when four children have no photo,
 * and somebody has to be able to come back tomorrow and download the same
 * sheets again because the first print jammed.
 *
 * The preflight report is the reason this table earns its place. Discovering
 * that a photo is missing after 400 cards have been laid onto 40 sheets is a
 * wasted ream; the run records what was wrong before anything was rendered.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('id_card_print_runs')) {
            return;
        }

        Schema::create('id_card_print_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->onDelete('cascade');
            $table->string('holder_type'); // student | staff

            /*
             * What was asked for: {"class_id": 4, "arm_id": 2} or
             * {"holder_ids": [1,2,3]}. Kept so "print the rest of JSS 2" is a
             * repeat of a recorded request rather than a re-typed one.
             */
            $table->json('filters')->nullable();

            /*
             * How it was imposed. Recorded per run, not read from config at
             * download time: a school that changes to a different sheet layout
             * next term must still be able to re-download this run and get the
             * pages that were originally cut to fit.
             */
            $table->json('layout')->nullable();

            $table->foreignId('template_id')->nullable()->constrained('id_card_templates')->nullOnDelete();

            /*
             * queued | preflight_failed | rendering | completed | failed
             *
             * The run row is its own progress record — there is no Bus batch
             * behind it. Imposition needs every card in hand at once to lay
             * out a sheet, so a run is one job, not one job per card, and a
             * batch of one has nothing to report that this column does not.
             */
            $table->string('status')->default('queued');

            /*
             * { "eligible": 42, "blocked": [{"holder_id": 9, "reason": "no_photo"}], ... }
             */
            $table->json('preflight_report')->nullable();

            $table->unsignedInteger('card_count')->default(0);
            $table->unsignedInteger('sheet_count')->default(0);

            /*
             * Storage path, not the bytes. A 400-card sheet set is tens of MB
             * and does not belong in a row that gets listed in an index view.
             */
            $table->string('pdf_path')->nullable();
            $table->unsignedBigInteger('pdf_bytes')->nullable();
            /*
             * Printable PDFs of children's faces and names are the most
             * sensitive artefact this product generates. They expire (doc §12
             * data minimisation) and a scheduled sweep deletes the file.
             */
            $table->timestamp('expires_at')->nullable();

            $table->text('error')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'status']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('id_card_print_runs');
    }
};