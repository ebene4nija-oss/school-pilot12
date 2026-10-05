<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columns that were added by editing migrations which had already run.
 *
 * `students.emergency_contacts`, `birth_certificate_reference` and
 * `passport_photo_path` were written into the original create-students
 * migration, and `parental_consents.ai_cross_border_consent_given` into the
 * original added-features one, after some databases had already been built
 * from the earlier versions. Laravel does not re-run a migration it has
 * recorded, so those databases never got the columns. Every query that
 * selected them failed, which surfaced as the parent portal's children list
 * returning a 500 and parents seeing no content.
 *
 * Each column is added only where it is missing, so this is a no-op on a
 * database built from the current migrations. These are the same fields the
 * original migrations define, not new personal data (doc §12).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            if (! Schema::hasColumn('students', 'emergency_contacts')) {
                $table->text('emergency_contacts')->nullable();
            }
            if (! Schema::hasColumn('students', 'birth_certificate_reference')) {
                $table->string('birth_certificate_reference')->nullable();
            }
            if (! Schema::hasColumn('students', 'passport_photo_path')) {
                $table->string('passport_photo_path')->nullable();
            }
        });

        if (! Schema::hasColumn('parental_consents', 'ai_cross_border_consent_given')) {
            Schema::table('parental_consents', function (Blueprint $table) {
                $table->boolean('ai_cross_border_consent_given')->default(true);
            });
        }
    }

    /**
     * Nothing to undo: on a current database these columns belong to the
     * original migrations, and dropping them here would take them from those.
     */
    public function down(): void
    {
    }
};
