<?php

namespace App\Enum;

enum GoogleReviewSessionStatus: string
{
    case READY_TO_LAUNCH = 'READY_TO_LAUNCH';
    case OUTBOUND_OPENED = 'OUTBOUND_OPENED';
    case RETURNED_TO_APP = 'RETURNED_TO_APP';
    case REWARD_READY = 'REWARD_READY';
    case REDEEMED = 'REDEEMED';
    case EXPIRED = 'EXPIRED';
}