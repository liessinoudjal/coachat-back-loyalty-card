<?php

namespace App\Enum;

enum NotificationChannel: string
{
    case EMAIL = 'email';
    case PUSH = 'push';
}