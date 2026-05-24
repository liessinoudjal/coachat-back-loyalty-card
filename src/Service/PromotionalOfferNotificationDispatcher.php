<?php

namespace App\Service;

use App\Entity\Customer;
use App\Entity\CustomerMerchantNotificationPreference;
use App\Entity\PromotionalOffer;
use App\Repository\CustomerMerchantNotificationPreferenceRepository;
use App\Repository\CustomerRepository;
use App\Repository\PromotionalOfferRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class PromotionalOfferNotificationDispatcher
{
    public function __construct(
        private readonly PromotionalOfferRepository $offerRepository,
        private readonly CustomerRepository $customerRepository,
        private readonly CustomerMerchantNotificationPreferenceRepository $preferenceRepository,
        private readonly NotificationService $notificationService,
        private readonly SignupAlertMailer $signupAlertMailer,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{start_notifications_sent_for_offers: int, ending_soon_notifications_sent_for_offers: int, flash_day_before_notifications_sent_for_offers: int, flash_day_of_notifications_sent_for_offers: int}
     */
    public function dispatch(\DateTimeImmutable $today): array
    {
        $tomorrow = $today->modify('+1 day');
        $flashDayBeforeOffers = $this->offerRepository->findFlashOffersForDayBefore($tomorrow);
        $startOffers = $this->offerRepository->findStartingOnDate($today);
        $endingSoonDate = $today->modify('+2 days');
        $endingOffers = $this->offerRepository->findEndingOnDate($endingSoonDate);

        $this->logger->info('promotional_offer.dispatch.started', [
            'today' => $today->format('Y-m-d'),
            'ending_soon_date' => $endingSoonDate->format('Y-m-d'),
            'flash_day_before_offer_count' => count($flashDayBeforeOffers),
            'start_offer_count' => count($startOffers),
            'ending_offer_count' => count($endingOffers),
        ]);

        // J-1 flash notifications (single-day offers starting tomorrow)
        $flashDayBeforeMarked = 0;
        foreach ($flashDayBeforeOffers as $offer) {
            $this->logger->debug('promotional_offer.dispatch.flash_day_before_processing', [
                'offer_id' => $offer->getId(),
                'merchant_id' => $offer->getMerchant()?->getId()?->toRfc4122(),
                'starts_on' => $offer->getStartsOn()?->format('Y-m-d'),
            ]);

            $stats = $this->notifyForFlashDayBefore($offer);
            $offer->setDayBeforeNotificationRecipientCount($stats['sent']);

            if ($stats['all_sent']) {
                $offer->setDayBeforeNotificationSentAt(new \DateTimeImmutable());
                $flashDayBeforeMarked++;
                $this->signupAlertMailer->notifyFlashOfferDispatched($offer, 'day_before');
            } else {
                $this->logger->warning('promotional_offer.dispatch.flash_day_before_failed_not_marked', [
                    'offer_id' => $offer->getId(),
                    'merchant_id' => $offer->getMerchant()?->getId()?->toRfc4122(),
                    'stats' => $stats,
                ]);
            }
        }

        // J-0 notifications (regular or flash)
        $startMarked = 0;
        $flashDayOfMarked = 0;
        foreach ($startOffers as $offer) {
            $this->logger->debug('promotional_offer.dispatch.start_offer_processing', [
                'offer_id' => $offer->getId(),
                'merchant_id' => $offer->getMerchant()?->getId()?->toRfc4122(),
                'starts_on' => $offer->getStartsOn()?->format('Y-m-d'),
                'ends_on' => $offer->getEndsOn()?->format('Y-m-d'),
                'is_flash' => $offer->isFlash(),
            ]);

            if ($offer->isFlash()) {
                $stats = $this->notifyForFlashDayOf($offer);
                $offer->setStartNotificationRecipientCount($stats['sent']);

                if ($stats['all_sent']) {
                    $offer->setStartNotificationSentAt(new \DateTimeImmutable());
                    $flashDayOfMarked++;
                    $this->signupAlertMailer->notifyFlashOfferDispatched($offer, 'day_of');
                } else {
                    $this->logger->warning('promotional_offer.dispatch.flash_day_of_failed_not_marked', [
                        'offer_id' => $offer->getId(),
                        'merchant_id' => $offer->getMerchant()?->getId()?->toRfc4122(),
                        'stats' => $stats,
                    ]);
                }
            } else {
                $stats = $this->notifyForOfferStart($offer);
                $offer->setStartNotificationRecipientCount($stats['sent']);

                if ($stats['all_sent']) {
                    $offer->setStartNotificationSentAt(new \DateTimeImmutable());
                    $startMarked++;
                } else {
                    $this->logger->warning('promotional_offer.dispatch.start_offer_failed_not_marked', [
                        'offer_id' => $offer->getId(),
                        'merchant_id' => $offer->getMerchant()?->getId()?->toRfc4122(),
                        'stats' => $stats,
                    ]);
                }
            }
        }

        $endingMarked = 0;
        foreach ($endingOffers as $offer) {
            $this->logger->debug('promotional_offer.dispatch.ending_offer_processing', [
                'offer_id' => $offer->getId(),
                'merchant_id' => $offer->getMerchant()?->getId()?->toRfc4122(),
                'starts_on' => $offer->getStartsOn()?->format('Y-m-d'),
                'ends_on' => $offer->getEndsOn()?->format('Y-m-d'),
            ]);

            $stats = $this->notifyForOfferEndingSoon($offer);
            $offer->setEndingSoonNotificationRecipientCount($stats['sent']);

            if ($stats['all_sent']) {
                $offer->setEndingSoonNotificationSentAt(new \DateTimeImmutable());
                $endingMarked++;
            } else {
                $this->logger->warning('promotional_offer.dispatch.ending_offer_failed_not_marked', [
                    'offer_id' => $offer->getId(),
                    'merchant_id' => $offer->getMerchant()?->getId()?->toRfc4122(),
                    'stats' => $stats,
                ]);
            }
        }

        $this->entityManager->flush();

        $this->logger->info('promotional_offer.dispatch.completed', [
            'today' => $today->format('Y-m-d'),
            'flash_day_before_notifications_sent_for_offers' => $flashDayBeforeMarked,
            'start_notifications_sent_for_offers' => $startMarked,
            'flash_day_of_notifications_sent_for_offers' => $flashDayOfMarked,
            'ending_soon_notifications_sent_for_offers' => $endingMarked,
        ]);

        return [
            'start_notifications_sent_for_offers' => $startMarked,
            'ending_soon_notifications_sent_for_offers' => $endingMarked,
            'flash_day_before_notifications_sent_for_offers' => $flashDayBeforeMarked,
            'flash_day_of_notifications_sent_for_offers' => $flashDayOfMarked,
        ];
    }

    /**
     * @return array{attempted: int, sent: int, failed: int, skipped: int, all_sent: bool}
     */
    private function notifyForFlashDayBefore(PromotionalOffer $offer): array
    {
        $merchant = $offer->getMerchant();
        if ($merchant === null) {
            $this->logger->warning('promotional_offer.dispatch.flash_day_before_skipped_missing_merchant', ['offer_id' => $offer->getId()]);

            return [
                'attempted' => 0,
                'sent' => 0,
                'failed' => 0,
                'skipped' => 0,
                'all_sent' => false,
            ];
        }

        return $this->notifyEligibleCustomers(
            $offer,
            fn (Customer $customer) => $this->notificationService->notifyPromotionalOfferFlashDayBefore($customer, $merchant, $offer),
        );
    }

    /**
     * @return array{attempted: int, sent: int, failed: int, skipped: int, all_sent: bool}
     */
    private function notifyForFlashDayOf(PromotionalOffer $offer): array
    {
        $merchant = $offer->getMerchant();
        if ($merchant === null) {
            $this->logger->warning('promotional_offer.dispatch.flash_day_of_skipped_missing_merchant', ['offer_id' => $offer->getId()]);

            return [
                'attempted' => 0,
                'sent' => 0,
                'failed' => 0,
                'skipped' => 0,
                'all_sent' => false,
            ];
        }

        return $this->notifyEligibleCustomers(
            $offer,
            fn (Customer $customer) => $this->notificationService->notifyPromotionalOfferFlashDayOf($customer, $merchant, $offer),
        );
    }

    /**
     * @return array{attempted: int, sent: int, failed: int, skipped: int, all_sent: bool}
     */
    private function notifyForOfferStart(PromotionalOffer $offer): array
    {
        $merchant = $offer->getMerchant();
        if ($merchant === null) {
            $this->logger->warning('promotional_offer.dispatch.start_offer_skipped_missing_merchant', [
                'offer_id' => $offer->getId(),
            ]);

            return [
                'attempted' => 0,
                'sent' => 0,
                'failed' => 0,
                'skipped' => 0,
                'all_sent' => false,
            ];
        }

        return $this->notifyEligibleCustomers(
            $offer,
            fn (Customer $customer) => $this->notificationService->notifyPromotionalOfferStarts($customer, $merchant, $offer),
        );
    }

    /**
     * @return array{attempted: int, sent: int, failed: int, skipped: int, all_sent: bool}
     */
    private function notifyForOfferEndingSoon(PromotionalOffer $offer): array
    {
        $merchant = $offer->getMerchant();
        if ($merchant === null) {
            $this->logger->warning('promotional_offer.dispatch.ending_offer_skipped_missing_merchant', [
                'offer_id' => $offer->getId(),
            ]);

            return [
                'attempted' => 0,
                'sent' => 0,
                'failed' => 0,
                'skipped' => 0,
                'all_sent' => false,
            ];
        }

        return $this->notifyEligibleCustomers(
            $offer,
            fn (Customer $customer) => $this->notificationService->notifyPromotionalOfferEndingSoon($customer, $merchant, $offer),
        );
    }

    /**
     * @param callable(Customer): bool $sendCallback
     *
     * @return array{attempted: int, sent: int, failed: int, skipped: int, all_sent: bool}
     */
    private function notifyEligibleCustomers(PromotionalOffer $offer, callable $sendCallback): array
    {
        $merchant = $offer->getMerchant();
        if ($merchant === null) {
            return [
                'attempted' => 0,
                'sent' => 0,
                'failed' => 0,
                'skipped' => 0,
                'all_sent' => false,
            ];
        }

        $customers = $this->customerRepository->findByMerchant($merchant);
        $this->logger->debug('promotional_offer.dispatch.offer_customers_loaded', [
            'offer_id' => $offer->getId(),
            'merchant_id' => $merchant->getId()?->toRfc4122(),
            'customer_count' => count($customers),
        ]);

        $attempted = 0;
        $sentCount = 0;
        $failedCount = 0;
        $skippedCount = 0;

        foreach ($customers as $customer) {
            if (!$this->canReceivePromotionalNotification($customer, $merchant)) {
                $skippedCount++;
                $this->logger->debug('promotional_offer.dispatch.offer_customer_skipped_preference', [
                    'offer_id' => $offer->getId(),
                    'merchant_id' => $merchant->getId()?->toRfc4122(),
                    'customer_id' => $customer->getId(),
                ]);

                continue;
            }

            $attempted++;

            $sent = $sendCallback($customer);
            if ($sent) {
                $sentCount++;
            } else {
                $failedCount++;

                $this->logger->warning('promotional_offer.dispatch.offer_customer_notification_failed', [
                    'offer_id' => $offer->getId(),
                    'merchant_id' => $merchant->getId()?->toRfc4122(),
                    'customer_id' => $customer->getId(),
                ]);
            }

            $this->logger->debug('promotional_offer.dispatch.offer_customer_notified', [
                'offer_id' => $offer->getId(),
                'merchant_id' => $merchant->getId()?->toRfc4122(),
                'customer_id' => $customer->getId(),
            ]);
        }

        return [
            'attempted' => $attempted,
            'sent' => $sentCount,
            'failed' => $failedCount,
            'skipped' => $skippedCount,
            'all_sent' => $failedCount === 0,
        ];
    }

    private function canReceivePromotionalNotification(Customer $customer, \App\Entity\Merchant $merchant): bool
    {
        $preference = $this->preferenceRepository->findOneByCustomerAndMerchant($customer, $merchant);

        if (!$preference instanceof CustomerMerchantNotificationPreference) {
            return true;
        }

        return $preference->isPromotionalOffersEnabled();
    }
}