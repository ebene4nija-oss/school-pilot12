<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A service, repair, fuel fill or breakdown on one bus. Field meanings are in the migration. */
class BusMaintenanceLog extends Model
{
    use BelongsToTenant;

    public const KINDS = ['service', 'repair', 'fuel', 'breakdown'];

    protected $table = 'bus_maintenance_logs';

    protected $fillable = ['school_id', 'bus_id', 'kind', 'logged_on', 'description', 'garage', 'cost', 'odometer_km', 'litres', 'next_service_on', 'next_service_km', 'reminded_on', 'back_in_service_at', 'created_by'];

    protected $casts = [
        'logged_on' => 'date', 'next_service_on' => 'date', 'reminded_on' => 'date', 'back_in_service_at' => 'datetime',
        'cost' => 'decimal:2', 'litres' => 'decimal:2', 'odometer_km' => 'integer', 'next_service_km' => 'integer',
    ];
}
