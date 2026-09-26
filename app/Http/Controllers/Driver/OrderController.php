<?php

namespace App\Http\Controllers\Driver;

use App\Enums\AssignmentStatus;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderAssignment;
use App\Services\AssignmentService;
use App\Services\OrderStateMachine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OrderController extends Controller
{
    public function __construct(
        protected AssignmentService $assignmentService,
        protected OrderStateMachine $stateMachine
    ) {}

    /**
     * Get active order currently handled by the authenticated driver.
     */
    public function activeOrder(Request $request): JsonResponse
    {
        $driver = $request->user()->driverProfile;

        if (! $driver) {
            return response()->json(['error' => 'DRIVER_PROFILE_NOT_FOUND', 'message' => 'Driver profile not found.'], Response::HTTP_NOT_FOUND);
        }

        $order = Order::with(['assignments' => fn ($q) => $q->where('driver_id', $driver->id)->latest()])
            ->where('current_driver_id', $driver->id)
            ->whereIn('status', [
                OrderStatus::ASSIGNED,
                OrderStatus::DRIVER_ACCEPTED,
                OrderStatus::PICKED_UP,
                OrderStatus::IN_TRANSIT,
            ])
            ->first();

        return response()->json([
            'order' => $order,
        ]);
    }

    /**
     * Get pending assignment offer awaiting driver's accept/reject response.
     */
    public function pendingAssignment(Request $request): JsonResponse
    {
        $driver = $request->user()->driverProfile;

        if (! $driver) {
            return response()->json(['error' => 'DRIVER_PROFILE_NOT_FOUND', 'message' => 'Driver profile not found.'], Response::HTTP_NOT_FOUND);
        }

        $assignment = OrderAssignment::with(['order', 'dispatcher'])
            ->where('driver_id', $driver->id)
            ->where('status', AssignmentStatus::PENDING)
            ->latest('assigned_at')
            ->first();

        return response()->json([
            'pending_assignment' => $assignment,
        ]);
    }

    /**
     * Accept incoming dispatch assignment.
     */
    public function acceptAssignment(Request $request, int $assignmentId): JsonResponse
    {
        $driver = $request->user()->driverProfile;

        if (! $driver) {
            return response()->json(['error' => 'DRIVER_PROFILE_NOT_FOUND', 'message' => 'Driver profile not found.'], Response::HTTP_NOT_FOUND);
        }

        $assignment = OrderAssignment::findOrFail($assignmentId);
        $updatedAssignment = $this->assignmentService->respondToAssignment($assignment, true, $driver);

        return response()->json([
            'message' => 'Assignment accepted',
            'assignment' => $updatedAssignment,
            'order' => $updatedAssignment->order->fresh(),
        ]);
    }

    /**
     * Reject incoming dispatch assignment.
     */
    public function rejectAssignment(Request $request, int $assignmentId): JsonResponse
    {
        $driver = $request->user()->driverProfile;

        if (! $driver) {
            return response()->json(['error' => 'DRIVER_PROFILE_NOT_FOUND', 'message' => 'Driver profile not found.'], Response::HTTP_NOT_FOUND);
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $assignment = OrderAssignment::findOrFail($assignmentId);
        $updatedAssignment = $this->assignmentService->respondToAssignment(
            $assignment,
            false,
            $driver,
            $validated['reason'] ?? null
        );

        return response()->json([
            'message' => 'Assignment rejected',
            'assignment' => $updatedAssignment,
        ]);
    }

    /**
     * Advance order delivery status (PICKED_UP -> IN_TRANSIT -> DELIVERED).
     */
    public function updateStatus(Request $request, int $orderId): JsonResponse
    {
        $driver = $request->user()->driverProfile;

        if (! $driver) {
            return response()->json(['error' => 'DRIVER_PROFILE_NOT_FOUND', 'message' => 'Driver profile not found.'], Response::HTTP_NOT_FOUND);
        }

        $validated = $request->validate([
            'status' => ['required', 'string'],
            'remarks' => ['nullable', 'string', 'max:255'],
        ]);

        $targetStatus = OrderStatus::tryFrom($validated['status']);
        if (! $targetStatus) {
            return response()->json([
                'error' => 'INVALID_STATUS',
                'message' => "Invalid status: {$validated['status']}",
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $order = Order::findOrFail($orderId);

        if ($order->current_driver_id !== $driver->id) {
            return response()->json([
                'error' => 'UNAUTHORIZED_ORDER_ACTION',
                'message' => 'You are not assigned to this order.',
            ], Response::HTTP_FORBIDDEN);
        }

        $updatedOrder = $this->stateMachine->transition(
            $order,
            $targetStatus,
            $request->user(),
            $validated['remarks'] ?? null
        );

        return response()->json([
            'message' => "Order transitioned to {$targetStatus->value}",
            'order' => $updatedOrder,
        ]);
    }

    /**
     * Get completed delivery history for driver.
     */
    public function history(Request $request): JsonResponse
    {
        $driver = $request->user()->driverProfile;

        if (! $driver) {
            return response()->json(['error' => 'DRIVER_PROFILE_NOT_FOUND', 'message' => 'Driver profile not found.'], Response::HTTP_NOT_FOUND);
        }

        $orders = Order::where('current_driver_id', $driver->id)
            ->where('status', OrderStatus::DELIVERED)
            ->latest('updated_at')
            ->paginate(20);

        return response()->json([
            'history' => $orders,
        ]);
    }
}
