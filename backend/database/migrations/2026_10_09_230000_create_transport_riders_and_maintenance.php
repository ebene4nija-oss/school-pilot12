<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * School bus: who rides (and so pays), the bus fee, and the bus's upkeep.
 *
 * Not every student uses the bus. A rider is signed up to a bus; only riders
 * are billed, one flat termly fee onto their term invoice. Bus costs (fuel,
 * repairs) are kept here and are not posted to the school's expenses — the
 * school chose to keep them separate.
 *
 * Personal data: a pickup point (a landmark, not a home address is asked
 * for) and nothing else new about a child.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buses', function (Blueprint $table) {
            $table->string('plate_number', 20)->nullable()->after('bus_number');
            $table->unsignedSmallInteger('capacity')->nullable()->after('plate_number');
            // in_service | off_road (a breakdown, or in the garage)
            $table->string('status', 10)->default('in_service')->after('capacity');
            $table->unsignedInteger('odometer_km')->nullable()->after('status');
        });

        Schema::create('transport_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->unique()->constrained()->cascadeOnDelete();
            // One flat fee per rider per term, in naira.
            $table->decimal('termly_fee', 12, 2)->default(0);
            // Days of warning before papers expire.
            $table->unsignedSmallInteger('renewal_warning_days')->default(30);
            $table->timestamps();
        });

        Schema::create('transport_riders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bus_id')->nullable()->constrained('buses')->nullOnDelete();
            $table->string('pickup_point', 120)->nullable();
            $table->date('started_on');
            $table->date('ended_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['school_id', 'ended_on']);
            $table->index(['school_id', 'student_id']);
        });

        Schema::create('transport_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            // pending | posted | void
            $table->string('status', 8)->default('pending');
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_item_id')->nullable()->constrained('invoice_items')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One bus fee per student per term, however often billing is run.
            $table->unique(['school_id', 'term_id', 'student_id']);
        });

        Schema::create('bus_maintenance_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bus_id')->constrained('buses')->cascadeOnDelete();
            // service | repair | fuel | breakdown
            $table->string('kind', 10);
            $table->date('logged_on');
            $table->string('description', 500)->nullable();
            $table->string('garage', 120)->nullable();
            $table->decimal('cost', 12, 2)->nullable();
            $table->unsignedInteger('odometer_km')->nullable();
            $table->decimal('litres', 8, 2)->nullable();
            // A service sets when the next one is due, by date and/or distance.
            $table->date('next_service_on')->nullable();
            $table->unsignedInteger('next_service_km')->nullable();
            // When the "service due" reminder for this entry went out, so it is sent once.
            $table->date('reminded_on')->nullable();
            // A breakdown: off the road until it is closed.
            $table->timestamp('back_in_service_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['school_id', 'bus_id', 'kind']);
        });

        Schema::create('bus_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bus_id')->constrained('buses')->cascadeOnDelete();
            // insurance | roadworthiness | vehicle_licence | driver_licence | other
            $table->string('kind', 16);
            $table->string('reference', 80)->nullable();
            $table->date('expires_on');
            $table->string('notes', 255)->nullable();
            // The day the last expiry reminder went out, so it is sent once.
            $table->date('reminded_on')->nullable();
            $table->timestamps();

            $table->index(['school_id', 'expires_on']);
        });
    }

    public function down(): void
    {
        foreach (['bus_documents', 'bus_maintenance_logs', 'transport_charges', 'transport_riders', 'transport_settings'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('buses', function (Blueprint $table) {
            $table->dropColumn(['plate_number', 'capacity', 'status', 'odometer_km']);
        });
    }
};
