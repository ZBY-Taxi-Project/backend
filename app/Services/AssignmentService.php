<?php

namespace App\Services;

use App\Enums\AssignmentStatus;
use App\Enums\DriverStatus;
use App\Enums\OrderStatus;
use App\Exceptions\OrderAssignmentException;
use App\Models\DriverProfile;
use App\Models\Order;
use App\Models\OrderAssignment;
use App\Models\OrderStatusHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AssignmentService
{
    public function __construct(
        protected OrderStateMachine $stateMachine
    ) {}

    /**
     * Assign an order to a driver with transactional pessimistic locking.
     *
     * @throws OrderAssignmentException
     */
    public function assign(int|Order $order, int|DriverProfile $driver, User $dispatcher): OrderAssignment
    {
        $orderId = $order instanceof Order ? $order->id : $order;
        $driverId = $driver instanceof DriverProfile ? $driver->id : $driver;

        return DB::transaction(function () use ($orderId, $driverId, $dispatcher) {
            // 1. Acquire row-level pessimistic locks inside transaction
            $orderRecord = Order::where('id', $orderId)->lockForUpdate()->firstOrFail();
            $driverRecord = DriverProfile::with('user')->where('id', $driverId)->lockForUpdate()->firstOrFail();

            // 2. Concurrency checks
            if (! in_array($orderRecord->status, [OrderStatus::NEW, OrderStatus::PENDING_DISPATCH], true)) {
                throw new OrderAssignmentException(
                    "Order is currently '{$orderRecord->status->value}' and cannot be assigned.",
                    'ORDER_NOT_DISPATCHABLE'
                );
            }

            if ($driverRecord->status !== DriverStatus::AVAILABLE) {
                throw new OrderAssignmentException(
                    "Driver {$driverRecord->user->name} is {$driverRecord->status->value} and cannot accept assignments.",
                    'DRIVER_UNAVAILABLE'
                );
            }

            // 3. Invalidate any existing pending assignments
            OrderAssignment::where('order_id', $orderRecord->id)
                ->where('status', AssignmentStatus::PENDING)
                ->update([
                    'status' => AssignmentStatus::CANCELLED,
                    'responded_at' => now(),
                    'rejection_reason' => 'Superseded by new dispatch assignment',
                ]);

            // 4. Create new pending assignment
            $assignment = OrderAssignment::create([
                'order_id' => $orderRecord->id,
                'driver_id' => $driverRecord->id,
                'dispatcher_id' => $dispatcher->id,
                'status' => AssignmentStatus::PENDING,
                'assigned_at' => now(),
            ]);

            // 5. Update order state & audit history
            $previousStatus = $orderRecord->status;
            $orderRecord->status = OrderStatus::ASSIGNED;
            $orderRecord->current_driver_id = $driverRecord->id;
            $orderRecord->save();

            OrderStatusHistory::create([
                'order_id' => $orderRecord->id,
                'from_status' => $previousStatus,
                'to_status' => OrderStatus::ASSIGNED,
                'changed_by_user_id' => $dispatcher->id,
                'remarks' => "Assigned to driver: {$driverRecord->user->name}",
            ]);

            $assignment = $assignment->load(['order', 'driver.user', 'dispatcher']);
            event(new \App\Events\OrderAssigned($orderRecord, $assignment));

            return $assignment;
        });
    }

    /**
     * Reassign an already assigned order to a different driver.
     *
     * @throws OrderAssignmentException
     */
    public function reassign(int|Order $order, int|DriverProfile $newDriver, User $dispatcher, ?string $reason = null): OrderAssignment
    {
        $orderId = $order instanceof Order ? $order->id : $order;
        $newDriverId = $newDriver instanceof DriverProfile ? $newDriver->id : $newDriver;

        return DB::transaction(function () use ($orderId, $newDriverId, $dispatcher, $reason) {
            $orderRecord = Order::where('id', $orderId)->lockForUpdate()->firstOrFail();
            $newDriverRecord = DriverProfile::with('user')->where('id', $newDriverId)->lockForUpdate()->firstOrFail();

            if (! in_array($orderRecord->status, [OrderStatus::ASSIGNED, OrderStatus::PENDING_DISPATCH], true)) {
                throw new OrderAssignmentException(
                    "Cannot reassign order in status '{$orderRecord->status->value}'.",
                    'ORDER_NOT_REASSIGNABLE'
                );
            }

            if ($newDriverRecord->status !== DriverStatus::AVAILABLE) {
                throw new OrderAssignmentException(
                    "Driver {$newDriverRecord->user->name} is not available.",
                    'DRIVER_UNAVAILABLE'
                );
            }

            // Cancel existing pending assignment
            OrderAssignment::where('order_id', $orderRecord->id)
                ->where('status', AssignmentStatus::PENDING)
                ->update([
                    'status' => AssignmentStatus::CANCELLED,
                    'responded_at' => now(),
                    'rejection_reason' => $reason ?: 'Reassigned by dispatcher',
                ]);

            // Create new assignment
            $assignment = OrderAssignment::create([
                'order_id' => $orderRecord->id,
                'driver_id' => $newDriverRecord->id,
                'dispatcher_id' => $dispatcher->id,
                'status' => AssignmentStatus::PENDING,
                'assigned_at' => now(),
            ]);

            $orderRecord->status = OrderStatus::ASSIGNED;
            $orderRecord->current_driver_id = $newDriverRecord->id;
            $orderRecord->save();

            OrderStatusHistory::create([
                'order_id' => $orderRecord->id,
                'from_status' => OrderStatus::ASSIGNED,
                'to_status' => OrderStatus::ASSIGNED,
                'changed_by_user_id' => $dispatcher->id,
                'remarks' => "Reassigned to {$newDriverRecord->user->name}. Reason: ".($reason ?: 'Dispatcher change'),
            ]);

            return $assignment->load(['order', 'driver.user', 'dispatcher']);
        });
    }

    /**
     * Cancel an active assignment and return order to PENDING_DISPATCH.
     */
    public function cancelAssignment(int|Order $order, User $dispatcher, ?string $reason = null): Order
    {
        $orderId = $order instanceof Order ? $order->id : $order;

        return DB::transaction(function () use ($orderId, $dispatcher, $reason) {
            $orderRecord = Order::where('id', $orderId)->lockForUpdate()->firstOrFail();

            if ($orderRecord->status !== OrderStatus::ASSIGNED) {
                throw new OrderAssignmentException(
                    "Only ASSIGNED orders can have assignment cancelled. Current: '{$orderRecord->status->value}'"
                );
            }

            OrderAssignment::where('order_id', $orderRecord->id)
                ->where('status', AssignmentStatus::PENDING)
                ->update([
                    'status' => AssignmentStatus::CANCELLED,
                    'responded_at' => now(),
                    'rejection_reason' => $reason ?: 'Assignment cancelled by dispatcher',
                ]);

            $orderRecord->status = OrderStatus::PENDING_DISPATCH;
            $orderRecord->current_driver_id = null;
            $orderRecord->save();

            OrderStatusHistory::create([
                'order_id' => $orderRecord->id,
                'from_status' => OrderStatus::ASSIGNED,
                'to_status' => OrderStatus::PENDING_DISPATCH,
                'changed_by_user_id' => $dispatcher->id,
                'remarks' => 'Assignment cancelled by dispatcher: '.($reason ?: 'No reason provided'),
            ]);

            return $orderRecord;
        });
    }

    /**
     * Respond to an assignment offer (Accept or Reject).
     *
     * @throws OrderAssignmentException
     */
    public function respondToAssignment(
        OrderAssignment $assignment,
        bool $accept,
        DriverProfile $driver,
        ?string $reason = null
    ): OrderAssignment {
        return DB::transaction(function () use ($assignment, $accept, $driver, $reason) {
            // Lock assignment, order, and driver
            $assignmentRecord = OrderAssignment::where('id', $assignment->id)->lockForUpdate()->firstOrFail();
            $orderRecord = Order::where('id', $assignmentRecord->order_id)->lockForUpdate()->firstOrFail();
            $driverRecord = DriverProfile::where('id', $driver->id)->lockForUpdate()->firstOrFail();

            if ($assignmentRecord->driver_id !== $driver->id) {
                throw new OrderAssignmentException(
                    'Unauthorized: You are not the driver assigned to this order.',
                    'UNAUTHORIZED_ASSIGNMENT_RESPONSE',
                    403
                );
            }

            if ($assignmentRecord->status !== AssignmentStatus::PENDING) {
                throw new OrderAssignmentException(
                    "Assignment offer has already been {$assignmentRecord->status->value}.",
                    'ASSIGNMENT_NOT_PENDING',
                    409
                );
            }

            if ($orderRecord->status !== OrderStatus::ASSIGNED) {
                throw new OrderAssignmentException(
                    "Cannot respond to assignment: order is currently in status '{$orderRecord->status->value}' and is no longer assigned.",
                    'ORDER_NOT_IN_ASSIGNED_STATE',
                    409
                );
            }

            if ($accept) {
                if ($driverRecord->status === DriverStatus::BUSY) {
                    throw new OrderAssignmentException(
                        "Driver is already busy with an active order.",
                        'DRIVER_ALREADY_BUSY',
                        409
                    );
                }

                if ($driverRecord->balance < 0) {
                    throw new OrderAssignmentException(
                        "Hamyonda mablag' yetarli emas. Balansingiz: {$driverRecord->balance} so'm. Iltimos, hisobingizni to'ldiring.",
                        'INSUFFICIENT_WALLET_BALANCE',
                        422
                    );
                }

                $assignmentRecord->status = AssignmentStatus::ACCEPTED;
                $assignmentRecord->responded_at = now();
                $assignmentRecord->save();

                // Calculate 10% commission fee
                $totalAmount = (float) $orderRecord->total_amount;
                if ($totalAmount <= 0) {
                    $dist = (float) ($orderRecord->estimated_distance_km ?: 2.0);
                    $rate = (float) ($orderRecord->rate_per_km ?: 2000);
                    $totalAmount = round($dist * $rate, 2);
                    $orderRecord->total_amount = $totalAmount;
                }

                $commissionFee = round($totalAmount * 0.10, 2);
                $orderRecord->commission_rate = 10.0;
                $orderRecord->commission_amount = $commissionFee;
                $orderRecord->status = OrderStatus::DRIVER_ACCEPTED;
                $orderRecord->save();

                // Check if commission already charged for this order (idempotency)
                $alreadyCharged = \App\Models\DriverWalletTransaction::where('order_id', $orderRecord->id)
                    ->where('type', 'commission')
                    ->exists();

                if (! $alreadyCharged && $commissionFee > 0) {
                    $newBalance = round($driverRecord->balance - $commissionFee, 2);
                    $driverRecord->balance = $newBalance;

                    $displayNumber = preg_replace('/^Buyurtma\s*/i', '#', $orderRecord->order_number);
                    if (! str_starts_with($displayNumber, '#')) {
                        $displayNumber = '#'.$displayNumber;
                    }

                    \App\Models\DriverWalletTransaction::create([
                        'driver_id' => $driverRecord->id,
                        'order_id' => $orderRecord->id,
                        'type' => 'commission',
                        'amount' => -$commissionFee,
                        'balance_after' => $newBalance,
                        'description' => "Buyurtma {$displayNumber} xizmat haqi (10%)",
                        'payment_method' => 'system',
                    ]);
                }

                $driverRecord->status = DriverStatus::BUSY;
                $driverRecord->save();

                OrderStatusHistory::create([
                    'order_id' => $orderRecord->id,
                    'from_status' => OrderStatus::ASSIGNED,
                    'to_status' => OrderStatus::DRIVER_ACCEPTED,
                    'changed_by_user_id' => $driver->user_id,
                    'remarks' => "Driver accepted assignment offer. 10% commission fee ({$commissionFee} so'm) charged.",
                ]);
            } else {
                $assignmentRecord->status = AssignmentStatus::REJECTED;
                $assignmentRecord->responded_at = now();
                $assignmentRecord->rejection_reason = $reason ?: 'Rejected by driver';
                $assignmentRecord->save();

                // Revert order back to PENDING_DISPATCH so another driver can be assigned
                $orderRecord->status = OrderStatus::PENDING_DISPATCH;
                $orderRecord->current_driver_id = null;
                $orderRecord->save();

                OrderStatusHistory::create([
                    'order_id' => $orderRecord->id,
                    'from_status' => OrderStatus::ASSIGNED,
                    'to_status' => OrderStatus::PENDING_DISPATCH,
                    'changed_by_user_id' => $driver->user_id,
                    'remarks' => "Driver rejected assignment: ".($reason ?: 'No reason provided'),
                ]);
            }

            $assignmentRecord = $assignmentRecord->load(['order', 'driver.user']);

            if ($accept) {
                event(new \App\Events\AssignmentAccepted($orderRecord, $assignmentRecord));
            } else {
                event(new \App\Events\AssignmentRejected($orderRecord, $assignmentRecord));
            }

            return $assignmentRecord;
        });
    }
}
