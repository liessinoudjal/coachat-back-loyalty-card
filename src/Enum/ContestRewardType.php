<?php

namespace App\Enum;

enum ContestRewardType: string
{
    case TEXT = 'TEXT';
    case CARD_STAMP = 'CARD_STAMP';
    case CARD_POINT = 'CARD_POINT';
}
