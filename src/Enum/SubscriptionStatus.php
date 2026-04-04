<?php

namespace App\Enum;

enum SubscriptionStatus: string
{
    case TRIAL = 'trial';
    case ACTIVE = 'active';
    case CANCELED = 'canceled';
    case SUSPENDED = 'suspended';
}