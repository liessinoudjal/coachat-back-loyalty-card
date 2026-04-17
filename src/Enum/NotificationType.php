<?php

namespace App\Enum;

enum NotificationType: string
{
    case MERCHANT_SIGNUP = 'merchant_signup';
    case CUSTOMER_SIGNUP = 'customer_signup';
    case CARD_CREATED = 'card_created';
    case POINTS_ADDED = 'points_added';
    case CARD_COMPLETED = 'card_completed';
    case REWARD_CLAIMED = 'reward_claimed';
}