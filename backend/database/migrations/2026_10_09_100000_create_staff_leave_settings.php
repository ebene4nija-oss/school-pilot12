<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A school's staff leave policy: which staff roles request leave through
 * SchoolPilot, the default days per leave type for staff without their own
 * allowance, and whether a request over the allowance is refused or left to
 * the approver. School policy only; no personal data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_leave_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('leave_roles')->nullable();
            $table->json('default_allowances')->nullable();
            $table->boolean('enforce_allowance')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_leave_settings');
    }
};
