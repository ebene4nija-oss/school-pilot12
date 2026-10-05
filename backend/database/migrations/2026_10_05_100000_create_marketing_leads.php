<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Leads from the public marketing site (schoolpilot.org.ng).
 *
 * Platform-level: a lead is a school that is not yet a tenant, so there is no
 * `school_id`. The person's name, phone, email and message are encrypted at
 * rest; nothing here is searchable by them on purpose (Landing PRD §14.6).
 *
 * `marketing_touches` holds the anonymous attribution behind a WhatsApp click:
 * a ref code and where the visitor came from, never who they are. Sales joins
 * a WhatsApp conversation to its touch by pasting the ref into the lead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_touches', function (Blueprint $table) {
            $table->id();
            $table->string('ref_code', 16)->unique();
            $table->string('cta', 32)->nullable();
            $table->string('landing_page', 255)->nullable();
            $table->string('referrer', 255)->nullable();
            $table->string('utm_source', 100)->nullable();
            $table->string('utm_medium', 100)->nullable();
            $table->string('utm_campaign', 100)->nullable();
            $table->string('utm_content', 100)->nullable();
            $table->string('utm_term', 100)->nullable();
            $table->json('pages')->nullable();
            $table->timestamps();
        });

        Schema::create('marketing_leads', function (Blueprint $table) {
            $table->id();
            $table->string('intent', 16);                 // demo | start | contact
            $table->text('full_name');                     // encrypted
            $table->text('phone');                         // encrypted, E.164
            $table->text('email')->nullable();             // encrypted
            $table->string('school_name', 150);
            $table->string('role', 32);
            $table->string('student_band', 16)->nullable();
            $table->json('sections')->nullable();
            $table->string('preferred_contact', 16)->default('whatsapp');
            $table->string('current_method', 16)->nullable();
            $table->text('message')->nullable();           // encrypted
            $table->string('preferred_subdomain', 63)->nullable();
            $table->text('migrate_what')->nullable();

            $table->string('ref_code', 16)->nullable()->index();
            $table->string('landing_page', 255)->nullable();
            $table->string('referrer', 255)->nullable();
            $table->string('utm_source', 100)->nullable();
            $table->string('utm_medium', 100)->nullable();
            $table->string('utm_campaign', 100)->nullable();
            $table->string('utm_content', 100)->nullable();
            $table->string('utm_term', 100)->nullable();
            $table->json('pages')->nullable();

            $table->string('status', 24)->default('new')->index();
            $table->unsignedSmallInteger('score')->default(0);
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('next_action_at')->nullable();
            $table->text('notes')->nullable();
            $table->string('lost_reason', 255)->nullable();
            $table->timestamp('consent_at');
            $table->timestamp('last_contact_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_leads');
        Schema::dropIfExists('marketing_touches');
    }
};
