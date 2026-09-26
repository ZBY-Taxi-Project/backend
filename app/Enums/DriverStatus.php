<?php

namespace App\Enums;

enum DriverStatus: string
{
    case OFFLINE = 'offline';
    case AVAILABLE = 'available';
    case BUSY = 'busy';
}
