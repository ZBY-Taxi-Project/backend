<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Order $order) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('dispatch-board'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'OrderCreated';
    }

    public function broadcastWith(): array
    {
        return [
            'order' => $this->order->load(['currentDriver.user', 'latestAssignment']),
        ];
    }
}
