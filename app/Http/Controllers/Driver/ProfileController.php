<?php

namespace App\Http\Controllers\Driver;

use App\Enums\DriverStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProfileController extends Controller
{
    /**
     * Update driver availability status (AVAILABLE or OFFLINE).
     */
    public function updateStatus(Request $request): JsonResponse
    {
        $driver = $request->user()->driverProfile;

        if (! $driver) {
            $driver = \App\Models\DriverProfile::create([
                'user_id' => $request->user()->id,
                'vehicle_type' => 'Standard Vehicle',
                'license_plate' => 'Unassigned',
                'status' => DriverStatus::AVAILABLE,
                'current_lat' => 41.3111,
                'current_lng' => 69.2405,
                'last_active_at' => now(),
            ]);
        }

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:available,offline'],
        ]);

        if ($driver->status === DriverStatus::BUSY) {
            return response()->json([
                'error' => 'DRIVER_BUSY',
                'message' => 'Cannot change status while busy with an active delivery.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $newStatus = DriverStatus::from($validated['status']);
        $driver->update([
            'status' => $newStatus,
            'last_active_at' => now(),
        ]);

        return response()->json([
            'message' => "Driver status updated to {$newStatus->value}",
            'driver_profile' => $driver,
        ]);
    }

    /**
     * Update driver real-time GPS coordinates.
     */
    public function updateLocation(Request $request): JsonResponse
    {
        $driver = $request->user()->driverProfile;

        if (! $driver) {
            $driver = \App\Models\DriverProfile::create([
                'user_id' => $request->user()->id,
                'vehicle_type' => 'Standard Vehicle',
                'license_plate' => 'Unassigned',
                'status' => DriverStatus::AVAILABLE,
                'current_lat' => 41.3111,
                'current_lng' => 69.2405,
                'last_active_at' => now(),
            ]);
        }

        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'status' => ['nullable', 'string', 'in:available,busy,offline'],
        ]);

        if (! empty($validated['status']) && $driver->status !== DriverStatus::BUSY) {
            $driver->update(['status' => DriverStatus::from($validated['status'])]);
        }

        $movement = \App\Services\PricingService::recordDriverMovement(
            $driver,
            (float) $validated['lat'],
            (float) $validated['lng']
        );

        return response()->json([
            'message' => 'Location updated and movement processed',
            'driver_status' => $driver->status->value,
            'current_lat' => $movement['current_lat'],
            'current_lng' => $movement['current_lng'],
            'distance_moved_km' => $movement['distance_moved_km'],
            'total_driver_odometer_km' => $movement['total_driver_odometer_km'],
            'active_order' => $movement['active_order'],
        ]);
    }
}
