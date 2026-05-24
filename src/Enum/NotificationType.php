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
    case PROMOTIONAL_OFFER_FLASH_DAY_BEFORE = 'promotional_offer_flash_day_before';
    case PROMOTIONAL_OFFER_FLASH_DAY_OF = 'promotional_offer_flash_day_of';
    case CONTEST_DAY_BEFORE_START = 'contest_day_before_start';
    case CONTEST_STARTS = 'contest_starts';
    case CONTEST_ENDING_SOON = 'contest_ending_soon';
    case CONTEST_DRAW_DAY = 'contest_draw_day';
    case CONTEST_PARTICIPATION_UPDATED = 'contest_participation_updated';
    case CONTEST_WINNER = 'contest_winner';
}