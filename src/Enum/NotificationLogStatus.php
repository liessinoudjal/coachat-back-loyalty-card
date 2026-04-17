<?php

namespace App\Enum;

enum NotificationLogStatus: string
{
    case PENDING = 'pending';
    case SENT = 'sent';
    case FAILED = 'failed';
}