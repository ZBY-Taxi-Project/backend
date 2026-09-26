<?php

namespace App\Http\Controllers\Dispatcher;

use App\Enums\DriverStatus;
use App\Http\Controllers\Controller;
use App\Models\DriverProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DriverController extends Controller
{
    /**
     * List all drivers with user info and active orders count.
     */
    public function index(Request $request): JsonResponse
    {
        $query = DriverProfile::with('user');

        if ($request->filled('status')) {
            $status = DriverStatus::tryFrom($request->query('status'));
            if ($status) {
                $query->where('status', $status);
            }
        }

        $drivers = $query->get()->map(function (DriverProfile $driver) {
            return [
                'id' => $driver->id,
                'user_id' => $driver->user_id,
                'name' => $driver->user->name,
                'email' => $driver->user->email,
                'phone' => $driver->user->phone,
                'vehicle_type' => $driver->vehicle_type,
                'license_plate' => $driver->license_plate,
                'status' => $driver->status->value,
                'current_lat' => $driver->current_lat,
                'current_lng' => $driver->current_lng,
                'last_active_at' => $driver->last_active_at,
            ];
        });

        $counts = [
            'total' => DriverProfile::count(),
            'available' => DriverProfile::where('status', DriverStatus::AVAILABLE)->count(),
            'busy' => DriverProfile::where('status', DriverStatus::BUSY)->count(),
            'offline' => DriverProfile::where('status', DriverStatus::OFFLINE)->count(),
        ];

        return response()->json([
            'drivers' => $drivers,
            'counts' => $counts,
        ]);
    }

    /**
     * Return list of drivers currently available for assignment.
     */
    public function available(): JsonResponse
    {
        $drivers = DriverProfile::with('user')
            ->where('status', DriverStatus::AVAILABLE)
            ->get()
            ->map(function (DriverProfile $driver) {
                return [
                    'id' => $driver->id,
                    'user_id' => $driver->user_id,
                    'name' => $driver->user->name,
                    'phone' => $driver->user->phone,
                    'vehicle_type' => $driver->vehicle_type,
                    'license_plate' => $driver->license_plate,
                    'status' => $driver->status->value,
                ];
            });

        return response()->json([
            'available_drivers' => $drivers,
        ]);
    }
}
