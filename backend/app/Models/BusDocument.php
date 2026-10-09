<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A bus's papers (insurance, roadworthiness, licences) and when each expires. */
class BusDocument extends Model
{
    use BelongsToTenant;

    public const KINDS = ['insurance', 'roadworthiness', 'vehicle_licence', 'driver_licence', 'other'];

    protected $table = 'bus_documents';

    protected $fillable = ['school_id', 'bus_id', 'kind', 'reference', 'expires_on', 'notes', 'reminded_on'];

    protected $casts = ['expires_on' => 'date', 'reminded_on' => 'date'];
}
