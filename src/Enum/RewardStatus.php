<?php

namespace App\Enum;

enum RewardStatus: string
{
    case PENDING = 'PENDING';
    case CLAIMED = 'CLAIMED';
    case CANCELLED = 'CANCELLED';
    case EXPIRED = 'EXPIRED';
}
