<?php

namespace App\Enum;

enum NotificationType: string
{
    case MERCHANT_SIGNUP = 'merchant_signup';
    case CUSTOMER_SIGNUP = 'customer_signup';
    case EQUIPIER_ASSIGNED = 'equipier_assigned';
    case EQUIPIER_REMOVED = 'equipier_removed';
    case CARD_CREATED = 'card_created';
    case POINTS_ADDED = 'points_added';
    case CARD_COMPLETED = 'card_completed';
    case REWARD_CLAIMED = 'reward_claimed';
    case PROMOTIONAL_OFFER_STARTS = 'promotional_offer_starts';
    case PROMOTIONAL_OFFER_ENDING_SOON = 'promotional_offer_ending_soon';
}