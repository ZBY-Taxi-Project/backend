<?php

namespace App\Http\Controllers;

use App\Enums\AssignmentStatus;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RealtimeController extends Controller
{
    /**
     * Polling synchronization endpoint for Dispatcher dashboard.
     * Returns newly created or updated orders/assignments since a given timestamp.
     */
    public function dispatcherSync(Request $request): JsonResponse
    {
        $since = $request->query('since');
        $query = Order::with(['currentDriver.user', 'latestAssignment.driver.user'])->latest('updated_at');

        if ($since) {
            $query->where('updated_at', '>', $since);
        }

        $updatedOrders = $query->limit(50)->get();

        $counts = [
            'all' => Order::count(),
            'pending_dispatch' => Order::whereIn('status', [OrderStatus::NEW, OrderStatus::PENDING_DISPATCH])->count(),
            'assigned' => Order::where('status', OrderStatus::ASSIGNED)->count(),
            'active' => Order::whereIn('status', [OrderStatus::DRIVER_ACCEPTED, OrderStatus::PICKED_UP, OrderStatus::IN_TRANSIT])->count(),
            'delivered' => Order::where('status', OrderStatus::DELIVERED)->count(),
            'cancelled' => Order::where('status', OrderStatus::CANCELLED)->count(),
            'pending_topups' => \App\Models\WalletTopupRequest::where('status', 'pending')->count(),
        ];

        $drivers = \App\Models\DriverProfile::with('user')->get()->map(function ($d) {
            return [
                'id' => $d->id,
                'user_id' => $d->user_id,
                'name' => $d->user ? $d->user->name : 'Driver #'.$d->id,
                'email' => $d->user ? $d->user->email : '',
                'phone' => $d->user ? $d->user->phone : '',
                'vehicle_type' => $d->vehicle_type,
                'license_plate' => $d->license_plate,
                'status' => $d->status->value,
                'balance' => (float) $d->balance,
                'current_lat' => $d->current_lat ? (float) $d->current_lat : null,
                'current_lng' => $d->current_lng ? (float) $d->current_lng : null,
                'last_active_at' => $d->last_active_at?->toIso8601String(),
            ];
        });

        return response()->json([
            'timestamp' => now()->toIso8601String(),
            'updated_orders' => $updatedOrders,
            'counts' => $counts,
            'drivers' => $drivers,
        ]);
    }

    /**
     * Server-Sent Events (SSE) stream for Dispatcher Dashboard.
     */
    public function dispatcherStream(Request $request): StreamedResponse
    {
        return new StreamedResponse(function () {
            // Keep connection alive with initial sync payload
            $counts = [
                'pending_dispatch' => Order::whereIn('status', [OrderStatus::NEW, OrderStatus::PENDING_DISPATCH])->count(),
                'assigned' => Order::where('status', OrderStatus::ASSIGNED)->count(),
            ];

            echo "event: connected\n";
            echo 'data: '.json_encode(['status' => 'connected', 'timestamp' => now()->toIso8601String(), 'counts' => $counts])."\n\n";
            ob_flush();
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Driver sync endpoint for active orders and pending incoming offers.
     */
    public function driverSync(Request $request): JsonResponse
    {
        $driver = $request->user()->driverProfile;

        if (! $driver) {
            return response()->json(['error' => 'DRIVER_PROFILE_NOT_FOUND'], 404);
        }

        $pendingAssignment = OrderAssignment::with(['order', 'dispatcher'])
            ->where('driver_id', $driver->id)
            ->where('status', AssignmentStatus::PENDING)
            ->latest('assigned_at')
            ->first();

        $activeOrder = Order::with(['assignments' => fn ($q) => $q->where('driver_id', $driver->id)->latest()])
            ->where('current_driver_id', $driver->id)
            ->whereIn('status', [
                OrderStatus::DRIVER_ACCEPTED,
                OrderStatus::PICKED_UP,
                OrderStatus::IN_TRANSIT,
            ])
            ->first();

        return response()->json([
            'timestamp' => now()->toIso8601String(),
            'driver_status' => $driver->status->value,
            'pending_assignment' => $pendingAssignment,
            'active_order' => $activeOrder,
        ]);
    }
}
