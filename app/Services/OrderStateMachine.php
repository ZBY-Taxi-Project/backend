<?php

namespace App\Services;

use App\Enums\DriverStatus;
use App\Enums\OrderStatus;
use App\Exceptions\InvalidOrderStateTransitionException;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class OrderStateMachine
{
    /**
     * Transition an order to a new status with validation and history tracking.
     *
     * @throws InvalidOrderStateTransitionException
     */
    public function transition(
        Order $order,
        OrderStatus $targetStatus,
        ?User $changedBy = null,
        ?string $remarks = null
    ): Order {
        $currentStatus = $order->status;

        if ($currentStatus === $targetStatus) {
            return $order;
        }

        if (! $currentStatus->canTransitionTo($targetStatus)) {
            throw new InvalidOrderStateTransitionException($currentStatus, $targetStatus);
        }

        return DB::transaction(function () use ($order, $currentStatus, $targetStatus, $changedBy, $remarks) {
            // Record status history audit
            OrderStatusHistory::create([
                'order_id' => $order->id,
                'from_status' => $currentStatus,
                'to_status' => $targetStatus,
                'changed_by_user_id' => $changedBy?->id,
                'remarks' => $remarks,
            ]);

            $order->status = $targetStatus;

            // Handle driver profile status changes
            if ($order->current_driver_id && $order->currentDriver) {
                if ($targetStatus === OrderStatus::DRIVER_ACCEPTED) {
                    $order->currentDriver->update(['status' => DriverStatus::BUSY]);
                } elseif ($targetStatus->isTerminal()) {
                    $order->currentDriver->update(['status' => DriverStatus::AVAILABLE]);
                }
            }

            $order->save();

            return $order->fresh(['currentDriver.user', 'assignments', 'statusHistories']);
        });
    }
}
