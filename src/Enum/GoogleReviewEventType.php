<?php

namespace App\Enum;

enum GoogleReviewEventType: string
{
    case MODULE_VIEWED = 'MODULE_VIEWED';
    case DETAIL_VIEWED = 'DETAIL_VIEWED';
    case OUTBOUND_CLICKED = 'OUTBOUND_CLICKED';
    case RETURN_CONFIRMED = 'RETURN_CONFIRMED';
    case WHEEL_SPUN = 'WHEEL_SPUN';
    case REWARD_REVEALED = 'REWARD_REVEALED';
    case QR_VIEWED = 'QR_VIEWED';
    case REWARD_REDEEMED = 'REWARD_REDEEMED';
}