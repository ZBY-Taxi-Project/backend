<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\DriverLocationLog;
use App\Models\DriverProfile;
use App\Models\Order;

class PricingService
{
    /**
     * Default price per kilometer in Uzbek So'm (UZS).
     */
    public const DEFAULT_RATE_PER_KM = 2000.0;

    /**
     * Default base fare (opening fee) in Uzbek So'm (UZS).
     */
    public const DEFAULT_BASE_FARE = 0.0;

    /**
     * Calculate geodesic distance between two GPS coordinates using the Haversine formula.
     *
     * @return float Distance in kilometers (accurate to meters, 3 decimal places).
     */
    public static function calculateDistance(
        ?float $lat1,
        ?float $lng1,
        ?float $lat2,
        ?float $lng2
    ): float {
        if ($lat1 === null || $lng1 === null || $lat2 === null || $lng2 === null) {
            return 0.0;
        }

        // Earth's mean radius in kilometers
        $earthRadiusKm = 6371.0;

        $lat1Rad = deg2rad($lat1);
        $lng1Rad = deg2rad($lng1);
        $lat2Rad = deg2rad($lat2);
        $lng2Rad = deg2rad($lng2);

        $dLat = $lat2Rad - $lat1Rad;
        $dLng = $lng2Rad - $lng1Rad;

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos($lat1Rad) * cos($lat2Rad) *
             sin($dLng / 2) * sin($dLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return round($earthRadiusKm * $c, 3);
    }

    /**
     * Calculate order price based on road distance.
     * 1 km = 2,000 so'm (or specified rate).
     *
     * @param  float  $distanceKm  Distance in kilometers
     * @param  float  $ratePerKm   Rate per kilometer in so'm (default 2,000 UZS)
     * @param  float  $baseFare    Base fare / flagfall fee in so'm (default 0 UZS)
     * @return float Calculated price in so'm, rounded to nearest 100 so'm.
     */
    public static function calculatePrice(
        float $distanceKm,
        float $ratePerKm = self::DEFAULT_RATE_PER_KM,
        float $baseFare = self::DEFAULT_BASE_FARE
    ): float {
        $rawPrice = $baseFare + ($distanceKm * $ratePerKm);

        // Round to nearest 100 so'm for realistic cash/card payment
        return round($rawPrice, -2);
    }

    /**
     * Record real GPS movement streamed from the Flutter mobile app.
     * Calculates distance moved, updates driver odometer, updates active order live distance & price.
     */
    public static function recordDriverMovement(
        DriverProfile $driver,
        float $newLat,
        float $newLng
    ): array {
        $prevLat = $driver->current_lat !== null ? (float) $driver->current_lat : null;
        $prevLng = $driver->current_lng !== null ? (float) $driver->current_lng : null;

        $deltaKm = 0.0;
        if ($prevLat !== null && $prevLng !== null) {
            $deltaKm = self::calculateDistance($prevLat, $prevLng, $newLat, $newLng);

            // Filter GPS jitter noise: movements less than 5 meters (0.005 km) are ignored
            if ($deltaKm < 0.005) {
                $deltaKm = 0.0;
            }

            // Filter impossible GPS teleportation jumps (e.g. > 100 km in a single ping)
            if ($deltaKm > 100.0) {
                $deltaKm = 0.0;
            }
        }

        // Find active order handled by this driver
        $activeOrder = Order::where('current_driver_id', $driver->id)
            ->whereIn('status', [
                OrderStatus::DRIVER_ACCEPTED,
                OrderStatus::PICKED_UP,
                OrderStatus::IN_TRANSIT,
            ])
            ->latest()
            ->first();

        $activeOrderData = null;

        if ($activeOrder && $deltaKm > 0) {
            // Only accumulate trip distance once driver is actively on road (picked up or in transit)
            $isDelivering = in_array($activeOrder->status, [OrderStatus::PICKED_UP, OrderStatus::IN_TRANSIT], true);

            if ($isDelivering) {
                $activeOrder->actual_distance_km = round((float) $activeOrder->actual_distance_km + $deltaKm, 3);
                $rate = (float) ($activeOrder->rate_per_km ?: self::DEFAULT_RATE_PER_KM);
                $base = (float) ($activeOrder->base_fare ?: self::DEFAULT_BASE_FARE);

                // Update real-time price based on accumulated odometer
                $activeOrder->total_amount = self::calculatePrice($activeOrder->actual_distance_km, $rate, $base);
                $activeOrder->save();
            }

            // Save breadcrumb in location logs
            DriverLocationLog::create([
                'driver_id' => $driver->id,
                'order_id' => $activeOrder->id,
                'lat' => $newLat,
                'lng' => $newLng,
                'distance_delta_km' => $deltaKm,
            ]);
        } elseif ($deltaKm > 0) {
            // Save breadcrumb even if driver doesn't have an active order
            DriverLocationLog::create([
                'driver_id' => $driver->id,
                'order_id' => null,
                'lat' => $newLat,
                'lng' => $newLng,
                'distance_delta_km' => $deltaKm,
            ]);
        }

        // Update driver profile coordinates & total lifetime odometer
        $driver->current_lat = $newLat;
        $driver->current_lng = $newLng;
        $driver->total_distance_km = round((float) $driver->total_distance_km + $deltaKm, 3);
        $driver->last_active_at = now();
        $driver->save();

        if ($activeOrder) {
            $activeOrderData = [
                'id' => $activeOrder->id,
                'order_number' => $activeOrder->order_number,
                'status' => $activeOrder->status->value,
                'estimated_distance_km' => (float) $activeOrder->estimated_distance_km,
                'actual_distance_km' => (float) $activeOrder->actual_distance_km,
                'rate_per_km' => (float) $activeOrder->rate_per_km,
                'base_fare' => (float) $activeOrder->base_fare,
                'current_price_som' => (float) $activeOrder->total_amount,
                'formatted_price' => number_format($activeOrder->total_amount, 0, '.', ' ')." so'm",
                'currency' => 'UZS',
            ];
        }

        return [
            'current_lat' => $newLat,
            'current_lng' => $newLng,
            'distance_moved_km' => $deltaKm,
            'total_driver_odometer_km' => (float) $driver->total_distance_km,
            'active_order' => $activeOrderData,
        ];
    }
}
