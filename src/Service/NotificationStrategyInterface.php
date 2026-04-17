<?php

namespace App\Service;

use App\Entity\Customer;
use App\Entity\Merchant;
use App\Enum\NotificationType;

interface NotificationStrategyInterface
{
    public function send(Merchant $merchant, Customer $customer, NotificationType $type, array $context = []): void;
}