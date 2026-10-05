<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * How parents reach a school about admissions — the school chooses.
 *
 * Not every Nigerian school wants an online application form. A school opens
 * or closes admissions for a session and turns on any mix of: the online form
 * (the existing `public_form_enabled`), WhatsApp, email and phone, each with
 * its own admissions contact. The school's public page shows only the
 * buttons it chose.
 *
 * `admissions_status` is null until a school sets it, so a school that has
 * never looked at admissions shows nothing rather than "closed". Schools that
 * already take applications online are carried over as open.
 *
 * Admission method is separate from onboarding: online applications run
 * review → offer → enrol; WhatsApp, email and phone admissions are handled
 * by the school, and staff enrol the accepted student themselves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admission_settings', function (Blueprint $table) {
            $table->string('admissions_status', 10)->nullable()->after('school_id');   // open | closed
            $table->string('admission_session', 9)->nullable()->after('admissions_status'); // 2026/2027
            $table->text('instructions')->nullable();
            $table->text('closed_message')->nullable();

            $table->boolean('whatsapp_enabled')->default(false);
            $table->string('whatsapp_number', 20)->nullable();
            $table->string('whatsapp_message', 300)->nullable();

            $table->boolean('email_enabled')->default(false);
            $table->string('admissions_email', 255)->nullable();
            $table->string('email_subject', 150)->nullable();

            $table->boolean('phone_enabled')->default(false);
            $table->string('admissions_phone', 20)->nullable();
        });

        DB::table('admission_settings')
            ->where('public_form_enabled', true)
            ->update(['admissions_status' => 'open']);
    }

    public function down(): void
    {
        Schema::table('admission_settings', function (Blueprint $table) {
            $table->dropColumn([
                'admissions_status', 'admission_session', 'instructions', 'closed_message',
                'whatsapp_enabled', 'whatsapp_number', 'whatsapp_message',
                'email_enabled', 'admissions_email', 'email_subject',
                'phone_enabled', 'admissions_phone',
            ]);
        });
    }
};
