<?php

namespace App\Events;

use App\Models\Order;
use App\Models\OrderAssignment;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AssignmentRejected implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Order $order,
        public OrderAssignment $assignment
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('dispatch-board'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'AssignmentRejected';
    }

    public function broadcastWith(): array
    {
        return [
            'order' => $this->order,
            'assignment' => $this->assignment,
            'reason' => $this->assignment->rejection_reason,
        ];
    }
}
