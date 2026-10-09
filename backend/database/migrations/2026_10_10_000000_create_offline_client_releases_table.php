<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Releases of the offline CBT lab client (desktop/), registered in Super
 * Admin. The installer itself is hosted elsewhere and linked by URL: the API
 * refuses executable uploads on every endpoint, and the installer with
 * WebView2 inside is far above the server's upload limit.
 *
 * Also: where a blog post's video is shown on the site besides the post.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offline_client_releases', function (Blueprint $table) {
            $table->id();
            $table->string('version', 20)->unique();
            $table->string('download_url', 500);
            $table->char('sha256', 64);
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->text('notes')->nullable();
            // Clients older than a required release are told to update before the next exam.
            $table->boolean('is_required')->default(false);
            $table->string('status', 12)->default('draft'); // draft | published | retired
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('downloads')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('blog_posts', function (Blueprint $table) {
            $table->string('video_placement', 40)->nullable()->after('video_url');
        });
    }

    public function down(): void
    {
        Schema::table('blog_posts', function (Blueprint $table) {
            $table->dropColumn('video_placement');
        });
        Schema::dropIfExists('offline_client_releases');
    }
};
