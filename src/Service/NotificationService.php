<?php

namespace App\Service;

use App\Entity\Customer;
use App\Entity\CustomerMerchantNotificationPreference;
use App\Entity\Merchant;
use App\Entity\NotificationLog;
use App\Entity\Reward;
use App\Entity\Transaction;
use App\Enum\LoyaltyProgramType;
use App\Enum\NotificationChannel;
use App\Enum\NotificationLogStatus;
use App\Enum\NotificationType;
use App\Repository\CustomerMerchantNotificationPreferenceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class NotificationService
{
    public function __construct(
        private readonly EmailNotificationStrategy $emailStrategy,
        private readonly PushNotificationStrategy $pushStrategy,
        private readonly CustomerMerchantNotificationPreferenceRepository $preferenceRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly string $appFrontBaseUrl,
    ) {
    }

    public function notifyPointsAdded(Transaction $transaction): void
    {
        $card = $transaction->getLoyaltyCard();
        $customer = $card?->getCustomer();
        $merchant = $transaction->getMerchant();

        if (!$customer instanceof Customer || !$merchant instanceof Merchant) {
            return;
        }

        $program = $card->getLoyaltyProgram();
        $unitLabel = $program?->getType() === LoyaltyProgramType::STAMP ? 'tampons' : 'points';

        $this->send(
            $merchant,
            $customer,
            NotificationType::POINTS_ADDED,
            [
                'transaction_id' => $transaction->getId(),
                'value_added' => max(0, (int) ($transaction->getPointsEarned() ?? 0)),
                'unit_label' => $unitLabel,
                'current_value' => $card->getCurrentValue(),
                'target_value' => $card->getTargetValue(),
                'reward_ready' => $card->isCompleted(),
                'dashboard_url' => $this->buildCustomerDashboardUrl(),
            ],
        );
    }

    public function notifyRewardClaimed(Reward $reward): void
    {
        $customer = $reward->getCustomer();
        $merchant = $reward->getMerchant();

        if (!$customer instanceof Customer || !$merchant instanceof Merchant) {
            return;
        }

        $this->send(
            $merchant,
            $customer,
            NotificationType::REWARD_CLAIMED,
            [
                'reward_id' => (string) $reward->getId(),
                'reward_description' => $reward->getRewardDescription() ?? 'Votre recompense',
                'dashboard_url' => $this->buildCustomerDashboardUrl(),
            ],
        );
    }

    public function send(Merchant $merchant, Customer $customer, NotificationType $type, array $context = []): void
    {
        $preference = $this->preferenceRepository->findOneByCustomerAndMerchant($customer, $merchant);
        if ($preference instanceof CustomerMerchantNotificationPreference && !$preference->isEnabled()) {
            return;
        }

        $recipientEmail = $customer->getEmail() ?? $customer->getUser()?->getEmail();
        if ($recipientEmail === null || trim($recipientEmail) === '') {
            return;
        }

        $channel = $this->resolveChannel($merchant);
        $log = $this->createPendingLog($merchant, $recipientEmail, $type, $this->buildSubject($type, $merchant));

        try {
            match ($channel) {
                NotificationChannel::EMAIL => $this->emailStrategy->send($merchant, $customer, $type, $context),
                NotificationChannel::PUSH => $this->pushStrategy->send($merchant, $customer, $type, $context),
            };

            $log
                ->setStatus(NotificationLogStatus::SENT)
                ->setSentAt(new \DateTimeImmutable())
                ->setErrorMessage(null);
            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            $log
                ->setStatus(NotificationLogStatus::FAILED)
                ->setErrorMessage($exception->getMessage());
            $this->entityManager->flush();

            $this->logger->error('Customer notification failed.', [
                'merchant_id' => $merchant->getId()?->toRfc4122(),
                'customer_id' => $customer->getId(),
                'type' => $type->value,
                'channel' => $channel->value,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    private function resolveChannel(Merchant $merchant): NotificationChannel
    {
        return $merchant->getPlan()?->isHasPushNotifications()
            ? NotificationChannel::PUSH
            : NotificationChannel::EMAIL;
    }

    private function buildCustomerDashboardUrl(): string
    {
        return rtrim($this->appFrontBaseUrl, '/');
    }

    private function createPendingLog(Merchant $merchant, string $recipientEmail, NotificationType $type, ?string $subject): NotificationLog
    {
        $log = (new NotificationLog())
            ->setMerchant($merchant)
            ->setRecipientEmail($recipientEmail)
            ->setType($type)
            ->setSubject($subject)
            ->setStatus(NotificationLogStatus::PENDING);

        $this->entityManager->persist($log);
        $this->entityManager->flush();

        return $log;
    }

    private function buildSubject(NotificationType $type, Merchant $merchant): ?string
    {
        return match ($type) {
            NotificationType::POINTS_ADDED => sprintf('Votre carte a ete mise a jour chez %s', $merchant->getCompanyName() ?? 'Coachat'),
            NotificationType::REWARD_CLAIMED => sprintf('Recompense recuperee chez %s', $merchant->getCompanyName() ?? 'Coachat'),
            default => null,
        };
    }
}