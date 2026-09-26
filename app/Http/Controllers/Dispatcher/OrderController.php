<?php

namespace App\Http\Controllers\Dispatcher;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Services\AssignmentService;
use App\Services\OrderStateMachine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class OrderController extends Controller
{
    public function __construct(
        protected AssignmentService $assignmentService,
        protected OrderStateMachine $stateMachine
    ) {}

    /**
     * List all orders with filters, search, and status counters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Order::with(['currentDriver.user', 'latestAssignment.driver.user'])
            ->latest();

        // Filter by specific status
        if ($request->filled('status')) {
            $status = OrderStatus::tryFrom($request->query('status'));
            if ($status) {
                $query->where('status', $status);
            }
        }

        // Search by order number, customer name, phone, or address
        if ($request->filled('search')) {
            $search = '%'.$request->query('search').'%';
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', $search)
                    ->orWhere('customer_name', 'like', $search)
                    ->orWhere('customer_phone', 'like', $search)
                    ->orWhere('pickup_address', 'like', $search)
                    ->orWhere('delivery_address', 'like', $search);
            });
        }

        $orders = $query->paginate($request->integer('per_page', 25));

        // Get status breakdown counts for dashboard summary tabs
        $counts = [
            'all' => Order::count(),
            'pending_dispatch' => Order::whereIn('status', [OrderStatus::NEW, OrderStatus::PENDING_DISPATCH])->count(),
            'assigned' => Order::where('status', OrderStatus::ASSIGNED)->count(),
            'active' => Order::whereIn('status', [OrderStatus::DRIVER_ACCEPTED, OrderStatus::PICKED_UP, OrderStatus::IN_TRANSIT])->count(),
            'delivered' => Order::where('status', OrderStatus::DELIVERED)->count(),
            'cancelled' => Order::where('status', OrderStatus::CANCELLED)->count(),
        ];

        return response()->json([
            'orders' => $orders,
            'counts' => $counts,
        ]);
    }

    /**
     * Show full order details including assignment history and timeline.
     */
    public function show(int $id): JsonResponse
    {
        $order = Order::with([
            'currentDriver.user',
            'createdBy',
            'assignments.driver.user',
            'assignments.dispatcher',
            'statusHistories.changedBy',
        ])->findOrFail($id);

        return response()->json([
            'order' => $order,
        ]);
    }

    /**
     * Create a new delivery order.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['required', 'string', 'max:50'],
            'pickup_address' => ['required', 'string'],
            'pickup_lat' => ['nullable', 'numeric'],
            'pickup_lng' => ['nullable', 'numeric'],
            'delivery_address' => ['required', 'string'],
            'delivery_lat' => ['nullable', 'numeric'],
            'delivery_lng' => ['nullable', 'numeric'],
            'total_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $orderNumber = 'ORD-'.strtoupper(Str::random(6));

        $order = Order::create([
            'order_number' => $orderNumber,
            'customer_name' => $validated['customer_name'],
            'customer_phone' => $validated['customer_phone'],
            'pickup_address' => $validated['pickup_address'],
            'pickup_lat' => $validated['pickup_lat'] ?? null,
            'pickup_lng' => $validated['pickup_lng'] ?? null,
            'delivery_address' => $validated['delivery_address'],
            'delivery_lat' => $validated['delivery_lat'] ?? null,
            'delivery_lng' => $validated['delivery_lng'] ?? null,
            'total_amount' => $validated['total_amount'] ?? 0,
            'status' => OrderStatus::PENDING_DISPATCH,
            'created_by_user_id' => $request->user()->id,
            'notes' => $validated['notes'] ?? null,
        ]);

        OrderStatusHistory::create([
            'order_id' => $order->id,
            'from_status' => OrderStatus::NEW,
            'to_status' => OrderStatus::PENDING_DISPATCH,
            'changed_by_user_id' => $request->user()->id,
            'remarks' => 'Order created and placed in dispatch queue',
        ]);

        return response()->json([
            'message' => 'Order created successfully',
            'order' => $order->fresh(['statusHistories']),
        ], Response::HTTP_CREATED);
    }

    /**
     * Assign order to a driver.
     */
    public function assign(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'driver_id' => ['required', 'integer', 'exists:driver_profiles,id'],
        ]);

        $assignment = $this->assignmentService->assign($id, $validated['driver_id'], $request->user());

        return response()->json([
            'message' => 'Order assigned successfully',
            'assignment' => $assignment,
            'order' => $assignment->order->fresh(['currentDriver.user', 'assignments']),
        ]);
    }

    /**
     * Reassign order to a different driver.
     */
    public function reassign(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'driver_id' => ['required', 'integer', 'exists:driver_profiles,id'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $assignment = $this->assignmentService->reassign(
            $id,
            $validated['driver_id'],
            $request->user(),
            $validated['reason'] ?? null
        );

        return response()->json([
            'message' => 'Order reassigned successfully',
            'assignment' => $assignment,
            'order' => $assignment->order->fresh(['currentDriver.user', 'assignments']),
        ]);
    }

    /**
     * Cancel active assignment for an order and revert to PENDING_DISPATCH.
     */
    public function cancelAssignment(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $order = $this->assignmentService->cancelAssignment($id, $request->user(), $validated['reason'] ?? null);

        return response()->json([
            'message' => 'Assignment cancelled, order returned to dispatch queue',
            'order' => $order->fresh(['statusHistories']),
        ]);
    }

    /**
     * Cancel an entire order.
     */
    public function cancelOrder(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $order = Order::findOrFail($id);
        $updatedOrder = $this->stateMachine->transition(
            $order,
            OrderStatus::CANCELLED,
            $request->user(),
            $validated['reason'] ?? 'Cancelled by dispatcher'
        );

        return response()->json([
            'message' => 'Order cancelled successfully',
            'order' => $updatedOrder,
        ]);
    }
}
