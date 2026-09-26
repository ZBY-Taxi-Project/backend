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
            return response()->json(['error' => 'DRIVER_PROFILE_NOT_FOUND', 'message' => 'Driver profile not found.'], Response::HTTP_NOT_FOUND);
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
            return response()->json(['error' => 'DRIVER_PROFILE_NOT_FOUND', 'message' => 'Driver profile not found.'], Response::HTTP_NOT_FOUND);
        }

        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $driver->update([
            'current_lat' => $validated['lat'],
            'current_lng' => $validated['lng'],
            'last_active_at' => now(),
        ]);

        return response()->json([
            'message' => 'Location updated',
            'current_lat' => $driver->current_lat,
            'current_lng' => $driver->current_lng,
        ]);
    }
}
