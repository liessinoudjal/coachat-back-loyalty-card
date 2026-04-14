<?php

namespace App\Enum;

enum SubscriptionStatus: string
{
    case TRIAL = 'trial';
    case ACTIVE = 'active';
    case CANCELING = 'canceling';
    case CANCELED = 'canceled';
    case SUSPENDED = 'suspended';
}