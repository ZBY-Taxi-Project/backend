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

            return $assignment->load(['order', 'driver.user', 'dispatcher']);
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
            // Lock assignment and order
            $assignmentRecord = OrderAssignment::where('id', $assignment->id)->lockForUpdate()->firstOrFail();
            $orderRecord = Order::where('id', $assignmentRecord->order_id)->lockForUpdate()->firstOrFail();

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
                    'ASSIGNMENT_NOT_PENDING'
                );
            }

            if ($accept) {
                $assignmentRecord->status = AssignmentStatus::ACCEPTED;
                $assignmentRecord->responded_at = now();
                $assignmentRecord->save();

                $orderRecord->status = OrderStatus::DRIVER_ACCEPTED;
                $orderRecord->save();

                $driver->update(['status' => DriverStatus::BUSY]);

                OrderStatusHistory::create([
                    'order_id' => $orderRecord->id,
                    'from_status' => OrderStatus::ASSIGNED,
                    'to_status' => OrderStatus::DRIVER_ACCEPTED,
                    'changed_by_user_id' => $driver->user_id,
                    'remarks' => "Driver accepted assignment offer.",
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

            return $assignmentRecord->load(['order', 'driver.user']);
        });
    }
}
