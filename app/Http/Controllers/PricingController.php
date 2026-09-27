<?php

namespace App\Http\Controllers;

use App\Services\PricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PricingController extends Controller
{
    /**
     * Calculate route distance and estimated price based on GPS coordinates.
     * Rate: 1 km = 2,000 so'm (UZS).
     */
    public function estimate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pickup_lat' => ['required', 'numeric', 'between:-90,90'],
            'pickup_lng' => ['required', 'numeric', 'between:-180,180'],
            'delivery_lat' => ['required', 'numeric', 'between:-90,90'],
            'delivery_lng' => ['required', 'numeric', 'between:-180,180'],
            'rate_per_km' => ['nullable', 'numeric', 'min:0'],
            'base_fare' => ['nullable', 'numeric', 'min:0'],
        ]);

        $ratePerKm = isset($validated['rate_per_km']) && (float) $validated['rate_per_km'] > 0
            ? (float) $validated['rate_per_km']
            : PricingService::DEFAULT_RATE_PER_KM;

        $baseFare = isset($validated['base_fare'])
            ? (float) $validated['base_fare']
            : PricingService::DEFAULT_BASE_FARE;

        $distanceKm = PricingService::calculateDistance(
            (float) $validated['pickup_lat'],
            (float) $validated['pickup_lng'],
            (float) $validated['delivery_lat'],
            (float) $validated['delivery_lng']
        );

        $price = PricingService::calculatePrice($distanceKm, $ratePerKm, $baseFare);

        return response()->json([
            'distance_km' => $distanceKm,
            'rate_per_km' => $ratePerKm,
            'base_fare' => $baseFare,
            'estimated_price' => $price,
            'formatted_price' => number_format($price, 0, '.', ' ')." so'm",
            'currency' => 'UZS',
            'explanation' => "Calculated at {$ratePerKm} so'm/km for {$distanceKm} km".($baseFare > 0 ? " + {$baseFare} so'm base fare" : ''),
        ]);
    }
}
