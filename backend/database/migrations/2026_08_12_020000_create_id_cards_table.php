<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An issued ID card.
 *
 * The row is the record of issuance, not the artwork. It exists because the
 * printed object outlives every other trace of itself: a card handed to a
 * child in September is still in a school bag in July, long after the PDF that
 * produced it was deleted and the design was replaced. To answer "is the thing
 * in my hand still valid?" a year later, the issuance has to be a durable row.
 *
 * This is NOT an access-control credential (doc §3). It carries no secret that
 * opens anything. The token below authorises exactly one thing: fetching a
 * minimal public description of the holder, so a gatekeeper with a phone can
 * see that the card is genuine and current. That is the whole of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('id_cards')) {
            return;
        }

        Schema::create('id_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->onDelete('cascade');

            /*
             * Not a polymorphic relation. `students` and `staff` are both
             * tenant-scoped tables with their own soft deletes, and a
             * morphTo would invite eager loads that cross the tenant scope.
             * An explicit pair, resolved in the service, keeps every query
             * visibly school-bound.
             */
            $table->string('holder_type'); // student | staff
            $table->unsignedBigInteger('holder_id');

            /*
             * Human-readable and printed on the card, so a bursar reading a
             * serial off a card over the phone can find the row. Unique per
             * school; format is minted in IdCardIssuanceService.
             */
            $table->string('serial', 64);

            /*
             * The QR payload. Random, not derived — the same reasoning as
             * ReportCardToken: a derived token lets anyone who learns the key
             * mint a valid code for any child, and gives no way to invalidate
             * a card issued in error.
             */
            $table->string('verify_token', 64)->unique();

            $table->foreignId('session_id')->nullable()->constrained('academic_sessions')->nullOnDelete();
            $table->date('issued_on');
            /*
             * Nullable on purpose. A staff card usually has no fixed expiry,
             * while a student card is normally cut to the end of session. A
             * null expiry means "valid until revoked", which the verification
             * page states in words rather than showing a blank date.
             */
            $table->date('expires_on')->nullable();

            // active | revoked | expired | replaced
            $table->string('status')->default('active');

            $table->timestamp('revoked_at')->nullable();
            /*
             * A category, never free text. The verification page is public:
             * whatever is in here is readable by anyone who scans the card, so
             * it must not be able to carry "expelled for stealing".
             */
            $table->string('revoked_reason')->nullable(); // lost | stolen | damaged | left_school | data_error | other
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();

            // The card this one supersedes, so a replacement chain is walkable.
            $table->foreignId('replaces_id')->nullable()->constrained('id_cards')->nullOnDelete();
            $table->unsignedInteger('print_count')->default(0);
            $table->timestamp('last_printed_at')->nullable();

            /*
             * Which design produced it. The checksum matters more than the id:
             * a template can be deleted, but knowing that a disputed card came
             * from a design whose checksum no longer matches any live template
             * is exactly what settles the dispute.
             */
            $table->foreignId('template_id')->nullable()->constrained('id_card_templates')->nullOnDelete();
            $table->string('template_checksum', 64)->nullable();

            /*
             * SHA-256 of the photo bytes at print time. A child's photo gets
             * updated mid-year; this is how the school can find every card
             * printed with the old face without keeping a copy of it.
             */
            $table->string('photo_fingerprint', 64)->nullable();

            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['school_id', 'serial']);
            $table->index(['school_id', 'holder_type', 'holder_id', 'status']);
            $table->index(['school_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('id_cards');
    }
};