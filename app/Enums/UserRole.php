<?php

namespace App\Enums;

enum UserRole: string
{
    case ADMIN = 'admin';
    case DISPATCHER = 'dispatcher';
    case DRIVER = 'driver';
}
