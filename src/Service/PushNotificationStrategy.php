<?php

namespace App\Service;

use App\Entity\Customer;
use App\Entity\Merchant;
use App\Enum\NotificationType;
use Psr\Log\LoggerInterface;

class PushNotificationStrategy implements NotificationStrategyInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function send(Merchant $merchant, Customer $customer, NotificationType $type, array $context = []): void
    {
        $this->logger->info('Push notification requested but push delivery is not implemented yet.', [
            'merchant_id' => $merchant->getId()?->toRfc4122(),
            'customer_id' => $customer->getId(),
            'type' => $type->value,
            'context' => $context,
        ]);

        throw new \RuntimeException('Push notifications are not implemented yet.');
    }
}