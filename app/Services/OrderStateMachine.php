<?php

namespace App\Services;

use App\Enums\AssignmentStatus;
use App\Enums\DriverStatus;
use App\Enums\OrderStatus;
use App\Exceptions\InvalidOrderStateTransitionException;
use App\Models\DriverProfile;
use App\Models\Order;
use App\Models\OrderAssignment;
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

            // When order is cancelled, cancel all active/pending assignments and refund commission if charged
            if ($targetStatus === OrderStatus::CANCELLED) {
                OrderAssignment::where('order_id', $order->id)
                    ->whereIn('status', [AssignmentStatus::PENDING, AssignmentStatus::ACCEPTED])
                    ->update([
                        'status' => AssignmentStatus::CANCELLED,
                        'responded_at' => now(),
                        'rejection_reason' => $remarks ?: 'Order was cancelled',
                    ]);

                // Refund commission if charged
                $chargedTx = \App\Models\DriverWalletTransaction::where('order_id', $order->id)
                    ->where('type', 'commission')
                    ->first();
                $alreadyRefunded = \App\Models\DriverWalletTransaction::where('order_id', $order->id)
                    ->where('type', 'refund')
                    ->exists();

                if ($chargedTx && ! $alreadyRefunded) {
                    $refundDriver = DriverProfile::find($chargedTx->driver_id);
                    if ($refundDriver) {
                        $refundAmount = abs($chargedTx->amount);
                        $newBalance = round($refundDriver->balance + $refundAmount, 2);
                        $refundDriver->balance = $newBalance;
                        $refundDriver->save();

                        $displayNumber = preg_replace('/^Buyurtma\s*/i', '#', $order->order_number);
                        if (! str_starts_with($displayNumber, '#')) {
                            $displayNumber = '#'.$displayNumber;
                        }

                        \App\Models\DriverWalletTransaction::create([
                            'driver_id' => $refundDriver->id,
                            'order_id' => $order->id,
                            'type' => 'refund',
                            'amount' => $refundAmount,
                            'balance_after' => $newBalance,
                            'description' => "Buyurtma {$displayNumber} bekor qilindi (Komissiya qaytarildi)",
                            'payment_method' => 'system',
                        ]);
                    }
                }
            }

            // Handle driver profile status changes
            if ($order->current_driver_id && $order->currentDriver) {
                if ($targetStatus === OrderStatus::DRIVER_ACCEPTED) {
                    $order->currentDriver->update(['status' => DriverStatus::BUSY]);
                } elseif ($targetStatus->isTerminal()) {
                    if ($order->currentDriver->status === DriverStatus::BUSY) {
                        $order->currentDriver->update(['status' => DriverStatus::AVAILABLE]);
                    }
                }
            }

            $order->save();

            $freshOrder = $order->fresh(['currentDriver.user', 'assignments', 'statusHistories']);
            event(new \App\Events\OrderStatusUpdated($freshOrder, $currentStatus->value, $targetStatus->value));

            return $freshOrder;
        });
    }
}
