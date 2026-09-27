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
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        // Filter by specific status (supports grouped status 'pending', 'active' or specific enum)
        if ($request->filled('status')) {
            $statusParam = $request->query('status');
            if ($statusParam === 'pending' || $statusParam === 'pending_dispatch') {
                $query->whereIn('status', [OrderStatus::NEW, OrderStatus::PENDING_DISPATCH]);
            } elseif ($statusParam === 'active') {
                $query->whereIn('status', [OrderStatus::DRIVER_ACCEPTED, OrderStatus::PICKED_UP, OrderStatus::IN_TRANSIT]);
            } else {
                $status = OrderStatus::tryFrom($statusParam);
                if ($status) {
                    $query->where('status', $status);
                }
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

        $orders = $query->paginate($request->integer('per_page', 15));

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
     * Update client data and order details (allowed even when assigned to driver).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->hasPermission('orders.edit')) {
            return response()->json([
                'error' => 'PERMISSION_DENIED',
                'message' => 'Buyurtma va mijoz ma\'lumotlarini o\'zgartirish uchun administrator ruxsati zarur (orders.edit talab qilinadi).',
            ], 403);
        }

        $order = Order::findOrFail($id);

        $validated = $request->validate([
            'customer_name' => ['sometimes', 'required', 'string', 'max:255'],
            'customer_phone' => ['sometimes', 'required', 'string', 'max:50'],
            'pickup_address' => ['sometimes', 'required', 'string'],
            'pickup_lat' => ['nullable', 'numeric'],
            'pickup_lng' => ['nullable', 'numeric'],
            'delivery_address' => ['sometimes', 'required', 'string'],
            'delivery_lat' => ['nullable', 'numeric'],
            'delivery_lng' => ['nullable', 'numeric'],
            'total_amount' => ['nullable', 'numeric', 'min:0'],
            'rate_per_km' => ['nullable', 'numeric', 'min:0'],
            'base_fare' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        if (array_key_exists('pickup_lat', $validated) || array_key_exists('delivery_lat', $validated)) {
            $pLat = $validated['pickup_lat'] ?? $order->pickup_lat;
            $pLng = $validated['pickup_lng'] ?? $order->pickup_lng;
            $dLat = $validated['delivery_lat'] ?? $order->delivery_lat;
            $dLng = $validated['delivery_lng'] ?? $order->delivery_lng;

            if ($pLat && $pLng && $dLat && $dLng) {
                $validated['estimated_distance_km'] = \App\Services\PricingService::calculateDistance((float) $pLat, (float) $pLng, (float) $dLat, (float) $dLng);
                if (empty($validated['total_amount'])) {
                    $rate = $validated['rate_per_km'] ?? $order->rate_per_km ?? \App\Services\PricingService::DEFAULT_RATE_PER_KM;
                    $base = $validated['base_fare'] ?? $order->base_fare ?? \App\Services\PricingService::DEFAULT_BASE_FARE;
                    $validated['total_amount'] = \App\Services\PricingService::calculatePrice($validated['estimated_distance_km'], (float) $rate, (float) $base);
                }
            }
        }

        $order->update($validated);

        OrderStatusHistory::create([
            'order_id' => $order->id,
            'from_status' => $order->status,
            'to_status' => $order->status,
            'changed_by_user_id' => $request->user()?->id,
            'remarks' => "Mijoz ma'lumotlari dispetcher tomonidan yangilandi",
        ]);

        $freshOrder = $order->fresh([
            'currentDriver.user',
            'createdBy',
            'assignments.driver.user',
            'assignments.dispatcher',
            'statusHistories.changedBy',
        ]);

        return response()->json([
            'message' => "Mijoz ma'lumotlari muvaffaqiyatli yangilandi",
            'order' => $freshOrder,
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
            'rate_per_km' => ['nullable', 'numeric', 'min:0'],
            'base_fare' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $pickupLat = isset($validated['pickup_lat']) ? (float) $validated['pickup_lat'] : null;
        $pickupLng = isset($validated['pickup_lng']) ? (float) $validated['pickup_lng'] : null;
        $deliveryLat = isset($validated['delivery_lat']) ? (float) $validated['delivery_lat'] : null;
        $deliveryLng = isset($validated['delivery_lng']) ? (float) $validated['delivery_lng'] : null;

        $ratePerKm = isset($validated['rate_per_km']) && (float) $validated['rate_per_km'] > 0
            ? (float) $validated['rate_per_km']
            : \App\Services\PricingService::DEFAULT_RATE_PER_KM;
        $baseFare = isset($validated['base_fare'])
            ? (float) $validated['base_fare']
            : \App\Services\PricingService::DEFAULT_BASE_FARE;

        $estimatedDistanceKm = \App\Services\PricingService::calculateDistance($pickupLat, $pickupLng, $deliveryLat, $deliveryLng);
        $calculatedPrice = \App\Services\PricingService::calculatePrice($estimatedDistanceKm, $ratePerKm, $baseFare);

        $totalAmount = ! empty($validated['total_amount']) && (float) $validated['total_amount'] > 0
            ? (float) $validated['total_amount']
            : $calculatedPrice;

        $order = \Illuminate\Support\Facades\DB::transaction(function () use (
            $validated,
            $pickupLat,
            $pickupLng,
            $deliveryLat,
            $deliveryLng,
            $estimatedDistanceKm,
            $ratePerKm,
            $baseFare,
            $totalAmount,
            $request
        ) {
            // Find max order number from recent orders without full table scan
            $recentOrders = Order::where('order_number', 'like', 'Buyurtma %')
                ->orWhere('order_number', 'like', 'Order %')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->limit(50)
                ->get();

            $maxNum = 0;
            foreach ($recentOrders as $ord) {
                if (preg_match('/(?:Order|Buyurtma)\s+(\d+)/i', $ord->order_number, $matches)) {
                    $num = (int) $matches[1];
                    if ($num > $maxNum) {
                        $maxNum = $num;
                    }
                }
            }

            if ($maxNum === 0) {
                $maxNum = (int) (Order::max('id') ?? 0);
            }

            $nextNum = $maxNum + 1;
            while (Order::where('order_number', 'Buyurtma '.$nextNum)->orWhere('order_number', 'Order '.$nextNum)->exists()) {
                $nextNum++;
            }
            $orderNumber = 'Buyurtma '.$nextNum;

            $newOrder = Order::create([
                'order_number' => $orderNumber,
                'customer_name' => $validated['customer_name'],
                'customer_phone' => $validated['customer_phone'],
                'pickup_address' => $validated['pickup_address'],
                'pickup_lat' => $pickupLat,
                'pickup_lng' => $pickupLng,
                'delivery_address' => $validated['delivery_address'],
                'delivery_lat' => $deliveryLat,
                'delivery_lng' => $deliveryLng,
                'estimated_distance_km' => $estimatedDistanceKm,
                'actual_distance_km' => 0.0,
                'rate_per_km' => $ratePerKm,
                'base_fare' => $baseFare,
                'total_amount' => $totalAmount,
                'status' => OrderStatus::PENDING_DISPATCH,
                'created_by_user_id' => $request->user()?->id,
                'notes' => $validated['notes'] ?? null,
            ]);

            OrderStatusHistory::create([
                'order_id' => $newOrder->id,
                'from_status' => OrderStatus::NEW,
                'to_status' => OrderStatus::PENDING_DISPATCH,
                'changed_by_user_id' => $request->user()?->id,
                'remarks' => 'Order created and placed in dispatch queue',
            ]);

            return $newOrder;
        });

        $freshOrder = $order->fresh([
            'currentDriver.user',
            'latestAssignment.driver.user',
            'assignments.driver.user',
            'statusHistories.changedBy',
        ]);
        event(new \App\Events\OrderCreated($freshOrder));

        return response()->json([
            'message' => 'Order created successfully',
            'order' => $freshOrder,
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
            'order' => $order->fresh([
                'currentDriver.user',
                'latestAssignment.driver.user',
                'assignments.driver.user',
                'statusHistories.changedBy',
            ]),
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
            'order' => $updatedOrder->fresh([
                'currentDriver.user',
                'latestAssignment.driver.user',
                'assignments.driver.user',
                'statusHistories.changedBy',
            ]),
        ]);
    }
}
