<?php

namespace App\Enum;

enum GoogleReviewRewardStatus: string
{
    case ACTIVE = 'ACTIVE';
    case REDEEMED = 'REDEEMED';
    case EXPIRED = 'EXPIRED';
    case CANCELLED = 'CANCELLED';
}