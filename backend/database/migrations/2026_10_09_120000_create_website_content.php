<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketing-site content an operator manages from the console: blog posts and
 * the competitor comparison table. The site reads both through /public/blog
 * and /public/comparison when it is built.
 *
 * No personal data: an author is a display name such as "SchoolPilot team".
 * The comparison is one JSON document so the table is saved atomically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_posts', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 100)->unique();
            $table->string('title', 160);
            $table->string('excerpt', 300);
            $table->longText('body'); // Markdown
            $table->string('cover_path')->nullable();
            $table->string('cover_alt', 200)->nullable();
            $table->string('video_url', 300)->nullable(); // YouTube or Vimeo link
            $table->string('meta_title', 70)->nullable();
            $table->string('meta_description', 170)->nullable();
            $table->string('author_name', 80)->default('SchoolPilot team');
            $table->string('status', 10)->default('draft'); // draft | published
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'published_at']);
        });

        Schema::create('marketing_comparison', function (Blueprint $table) {
            $table->id();
            $table->json('data');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_comparison');
        Schema::dropIfExists('blog_posts');
    }
};
