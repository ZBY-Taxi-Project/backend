<?php

namespace App\Enums;

enum OrderStatus: string
{
    case NEW = 'new';
    case PENDING_DISPATCH = 'pending_dispatch';
    case ASSIGNED = 'assigned';
    case DRIVER_ACCEPTED = 'driver_accepted';
    case PICKED_UP = 'picked_up';
    case IN_TRANSIT = 'in_transit';
    case DELIVERED = 'delivered';
    case CANCELLED = 'cancelled';

    /**
     * Determines whether this status is terminal.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::DELIVERED, self::CANCELLED], true);
    }

    /**
     * Return valid next states according to business state machine.
     *
     * @return array<OrderStatus>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::NEW => [self::PENDING_DISPATCH, self::CANCELLED],
            self::PENDING_DISPATCH => [self::ASSIGNED, self::CANCELLED],
            self::ASSIGNED => [self::DRIVER_ACCEPTED, self::PENDING_DISPATCH, self::CANCELLED],
            self::DRIVER_ACCEPTED => [self::PICKED_UP, self::CANCELLED],
            self::PICKED_UP => [self::IN_TRANSIT, self::CANCELLED],
            self::IN_TRANSIT => [self::DELIVERED, self::CANCELLED],
            self::DELIVERED, self::CANCELLED => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
