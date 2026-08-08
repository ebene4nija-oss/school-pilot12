<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staff GPS clock-in replaces a biometric reader (doc §3), so the school's
     * gate coordinates are what make a clock-in verifiable rather than a
     * self-declaration.
     */
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('address');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->unsignedInteger('geofence_radius_meters')->default(250)->after('longitude');
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'geofence_radius_meters']);
        });
    }
};
