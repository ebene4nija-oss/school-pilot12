<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * School public portal & branded login.
 *
 * Every school's subdomain shows its own name, crest and hero rather than a
 * generic SchoolPilot sign-in card, and a school that publishes its profile
 * gets a small indexable web presence: about, contact, sections, admissions.
 *
 * Only the school's own institutional details live here — no person's data.
 * The hero photo is the one field that could carry a child's likeness, so the
 * upload records who confirmed it shows no identifiable pupil (or that the
 * school holds parental consent), and when.
 *
 * Publishing is opt-in: an indexed page says publicly that the school is a
 * SchoolPilot customer, which the marketing site deliberately never does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->string('short_name', 40)->nullable();
            $table->string('motto', 150)->nullable();
            $table->text('about')->nullable();
            $table->string('hero_image_url', 500)->nullable();
            $table->timestamp('hero_image_confirmed_at')->nullable();
            $table->foreignId('hero_image_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('state', 40)->nullable();
            $table->string('lga', 80)->nullable();
            $table->string('website', 255)->nullable();
            $table->json('social_links')->nullable();
            $table->string('school_type', 20)->nullable(); // day | boarding | day_and_boarding
            $table->string('brand_color', 7)->nullable();
            $table->boolean('public_profile_published')->default(false);
            $table->timestamp('public_profile_published_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hero_image_confirmed_by');
            $table->dropColumn([
                'short_name', 'motto', 'about', 'hero_image_url', 'hero_image_confirmed_at',
                'state', 'lga', 'website', 'social_links', 'school_type', 'brand_color',
                'public_profile_published', 'public_profile_published_at',
            ]);
        });
    }
};
