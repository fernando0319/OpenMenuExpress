<?php

namespace App\Services\CourierNetwork;

/**
 * Straight-line distances on the map, good enough to rank couriers around a store.
 */
final class Distance
{
    private const EARTH_RADIUS_KM = 6371.0;

    public static function km(float $fromLat, float $fromLng, float $toLat, float $toLng): float
    {
        $dLat = deg2rad($toLat - $fromLat);
        $dLng = deg2rad($toLng - $fromLng);

        $a = sin($dLat / 2) ** 2 + cos(deg2rad($fromLat)) * cos(deg2rad($toLat)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_KM * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Latitude and longitude spans around a point that hold a circle of that radius,
     * to narrow a query before measuring exactly.
     *
     * @return array{lat: float, lng: float}
     */
    public static function span(float $lat, float $radiusKm): array
    {
        $latSpan = rad2deg($radiusKm / self::EARTH_RADIUS_KM);

        return [
            'lat' => $latSpan,
            'lng' => $latSpan / max(cos(deg2rad($lat)), 0.01),
        ];
    }
}
