<?php

namespace App\Services;

class GeoService
{
    /**
     * Great-circle distance between two coordinates, in meters (haversine).
     */
    public function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000.0;

        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $dPhi = deg2rad($lat2 - $lat1);
        $dLambda = deg2rad($lng2 - $lng1);

        $a = sin($dPhi / 2) ** 2
            + cos($phi1) * cos($phi2) * sin($dLambda / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * Score ramp: 5000 within 1 km, 1000 within 10 km, 100 within 100 km,
     * 5 within 1000 km, and zero beyond. Linear decay between the anchors.
     */
    public function scoreForDistance(float $meters): int
    {
        $km = $meters / 1000;

        // [distance in km, score] anchors, steepest drop first.
        $anchors = [
            [1, 5000],
            [10, 1000],
            [100, 100],
            [1000, 5],
        ];

        if ($km <= $anchors[0][0]) {
            return $anchors[0][1];
        }

        foreach ($anchors as $i => [$distance, $score]) {
            if ($km > $distance) {
                continue;
            }

            if ($km === $distance) {
                return $score;
            }

            [$prevDistance, $prevScore] = $anchors[$i - 1];
            $t = ($km - $prevDistance) / ($distance - $prevDistance);

            return (int) round($prevScore + $t * ($score - $prevScore));
        }

        return 0;
    }
}
