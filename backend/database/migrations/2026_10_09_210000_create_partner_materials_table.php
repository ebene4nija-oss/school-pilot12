<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What SchoolPilot gives its partners to sell with: brochures, social
 * images, message scripts and links. Uploaded in Super Admin, downloaded on
 * the partner page. Files sit on the private disk and are streamed only to
 * signed-in partners.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_materials', function (Blueprint $table) {
            $table->id();
            $table->string('title', 160);
            $table->string('description', 500)->nullable();
            // brochure | social | script | video | other
            $table->string('category', 12)->default('other');
            // file | text | link
            $table->string('kind', 8);
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->string('mime', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            // A script; {link}, {code} and {name} are filled in per partner.
            $table->text('body')->nullable();
            $table->string('url', 500)->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_published')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_materials');
    }
};
