<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Order $order,
        public string $previousStatus,
        public string $newStatus
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('dispatch-board'),
            new Channel('order-channel.'.$this->order->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'OrderStatusUpdated';
    }

    public function broadcastWith(): array
    {
        return [
            'order' => $this->order->load(['currentDriver.user']),
            'previous_status' => $this->previousStatus,
            'new_status' => $this->newStatus,
        ];
    }
}
