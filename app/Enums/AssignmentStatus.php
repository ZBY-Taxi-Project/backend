<?php

namespace App\Enums;

enum AssignmentStatus: string
{
    case PENDING = 'pending';
    case ACCEPTED = 'accepted';
    case REJECTED = 'rejected';
    case TIMEOUT = 'timeout';
    case CANCELLED = 'cancelled';
}
