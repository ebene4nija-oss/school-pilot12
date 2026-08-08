<?php

namespace App\Services;

use App\Models\School;

/**
 * Distance checks for phone-GPS staff clock-in (doc §3 — no dedicated hardware).
 */
class GeofenceService
{
    private const EARTH_RADIUS_METRES = 6371000;

    /**
     * Great-circle distance between two coordinates, in metres.
     */
    public function distanceInMetres(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $latFrom = deg2rad($lat1);
        $latTo = deg2rad($lat2);
        $latDelta = $latTo - $latFrom;
        $lonDelta = deg2rad($lon2) - deg2rad($lon1);

        $a = sin($latDelta / 2) ** 2
            + cos($latFrom) * cos($latTo) * sin($lonDelta / 2) ** 2;

        return 2 * self::EARTH_RADIUS_METRES * asin(min(1.0, sqrt($a)));
    }

    /**
     * @return array{configured:bool,within:bool,distance:float|null,radius:int}
     *
     * `configured` is false when the school has no coordinates on file. The
     * caller decides what to do about that — this service does not guess.
     */
    public function check(School $school, float $latitude, float $longitude): array
    {
        $radius = (int) ($school->geofence_radius_meters ?: 250);

        if ($school->latitude === null || $school->longitude === null) {
            return ['configured' => false, 'within' => false, 'distance' => null, 'radius' => $radius];
        }

        $distance = $this->distanceInMetres(
            (float) $school->latitude,
            (float) $school->longitude,
            $latitude,
            $longitude
        );

        return [
            'configured' => true,
            'within' => $distance <= $radius,
            'distance' => round($distance, 2),
            'radius' => $radius,
        ];
    }
}
