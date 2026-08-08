<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-school report card designs.
 *
 * A school codes its own report card against the documented template contract
 * (docs/report-card-template-contract.md) and imports the bundle. The markup
 * is deliberately NOT Blade or Twig — a school-uploaded Blade file is remote
 * code execution. It is a restricted mustache-style dialect rendered by
 * App\Services\ReportCard\TemplateRenderer and then HTML-sanitized.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('report_card_templates')) {
            Schema::create('report_card_templates', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained()->onDelete('cascade');
                $table->string('name');
                $table->string('slug');
                $table->unsignedInteger('version')->default(1);
                $table->text('description')->nullable();
                // Contract version the bundle declares. Bumped when the token
                // vocabulary changes so old bundles fail loudly, not silently.
                $table->string('engine')->default('schoolpilot-report-card/v1');

                $table->longText('body');           // sanitized template markup
                $table->longText('styles')->nullable(); // sanitized CSS
                // { "size": "A4", "orientation": "portrait", "margins_mm": {...} }
                $table->json('page_settings')->nullable();
                // Regions the bundle declared it implements, e.g. header,
                // scores_table, affective_traits, comments, footer.
                $table->json('regions')->nullable();
                $table->json('validation_report')->nullable();

                $table->string('checksum', 64);
                $table->string('status')->default('draft'); // draft | active | archived
                $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('imported_at')->nullable();
                $table->timestamps();

                $table->unique(['school_id', 'slug', 'version']);
                $table->index(['school_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('report_card_templates');
    }
};
