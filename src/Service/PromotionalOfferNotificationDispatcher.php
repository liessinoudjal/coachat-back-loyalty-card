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
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{start_notifications_sent_for_offers: int, ending_soon_notifications_sent_for_offers: int}
     */
    public function dispatch(\DateTimeImmutable $today): array
    {
        $startOffers = $this->offerRepository->findStartingOnDate($today);
        $endingSoonDate = $today->modify('+2 days');
        $endingOffers = $this->offerRepository->findEndingOnDate($endingSoonDate);

        $this->logger->info('promotional_offer.dispatch.started', [
            'today' => $today->format('Y-m-d'),
            'ending_soon_date' => $endingSoonDate->format('Y-m-d'),
            'start_offer_count' => count($startOffers),
            'ending_offer_count' => count($endingOffers),
        ]);

        $startMarked = 0;
        foreach ($startOffers as $offer) {
            $this->logger->debug('promotional_offer.dispatch.start_offer_processing', [
                'offer_id' => $offer->getId(),
                'merchant_id' => $offer->getMerchant()?->getId()?->toRfc4122(),
                'starts_on' => $offer->getStartsOn()?->format('Y-m-d'),
                'ends_on' => $offer->getEndsOn()?->format('Y-m-d'),
            ]);

            $startOk = $this->notifyForOfferStart($offer);
            if ($startOk) {
                $offer->setStartNotificationSentAt(new \DateTimeImmutable());
                $startMarked++;
            } else {
                $this->logger->warning('promotional_offer.dispatch.start_offer_failed_not_marked', [
                    'offer_id' => $offer->getId(),
                    'merchant_id' => $offer->getMerchant()?->getId()?->toRfc4122(),
                ]);
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

            $endingOk = $this->notifyForOfferEndingSoon($offer);
            if ($endingOk) {
                $offer->setEndingSoonNotificationSentAt(new \DateTimeImmutable());
                $endingMarked++;
            } else {
                $this->logger->warning('promotional_offer.dispatch.ending_offer_failed_not_marked', [
                    'offer_id' => $offer->getId(),
                    'merchant_id' => $offer->getMerchant()?->getId()?->toRfc4122(),
                ]);
            }
        }

        $this->entityManager->flush();

        $this->logger->info('promotional_offer.dispatch.completed', [
            'today' => $today->format('Y-m-d'),
            'start_notifications_sent_for_offers' => $startMarked,
            'ending_soon_notifications_sent_for_offers' => $endingMarked,
        ]);

        return [
            'start_notifications_sent_for_offers' => $startMarked,
            'ending_soon_notifications_sent_for_offers' => $endingMarked,
        ];
    }

    private function notifyForOfferStart(PromotionalOffer $offer): bool
    {
        $merchant = $offer->getMerchant();
        if ($merchant === null) {
            $this->logger->warning('promotional_offer.dispatch.start_offer_skipped_missing_merchant', [
                'offer_id' => $offer->getId(),
            ]);

            return false;
        }

        $customers = $this->customerRepository->findByMerchant($merchant);
        $this->logger->debug('promotional_offer.dispatch.start_offer_customers_loaded', [
            'offer_id' => $offer->getId(),
            'merchant_id' => $merchant->getId()?->toRfc4122(),
            'customer_count' => count($customers),
        ]);

        $hasFailure = false;
        foreach ($customers as $customer) {
            if (!$this->canReceivePromotionalNotification($customer, $merchant)) {
                $this->logger->debug('promotional_offer.dispatch.start_offer_customer_skipped_preference', [
                    'offer_id' => $offer->getId(),
                    'merchant_id' => $merchant->getId()?->toRfc4122(),
                    'customer_id' => $customer->getId(),
                ]);

                continue;
            }

            $sent = $this->notificationService->notifyPromotionalOfferStarts($customer, $merchant, $offer);
            if (!$sent) {
                $hasFailure = true;

                $this->logger->warning('promotional_offer.dispatch.start_offer_customer_notification_failed', [
                    'offer_id' => $offer->getId(),
                    'merchant_id' => $merchant->getId()?->toRfc4122(),
                    'customer_id' => $customer->getId(),
                ]);
            }

            $this->logger->debug('promotional_offer.dispatch.start_offer_customer_notified', [
                'offer_id' => $offer->getId(),
                'merchant_id' => $merchant->getId()?->toRfc4122(),
                'customer_id' => $customer->getId(),
            ]);
        }

        return !$hasFailure;
    }

    private function notifyForOfferEndingSoon(PromotionalOffer $offer): bool
    {
        $merchant = $offer->getMerchant();
        if ($merchant === null) {
            $this->logger->warning('promotional_offer.dispatch.ending_offer_skipped_missing_merchant', [
                'offer_id' => $offer->getId(),
            ]);

            return false;
        }

        $customers = $this->customerRepository->findByMerchant($merchant);
        $this->logger->debug('promotional_offer.dispatch.ending_offer_customers_loaded', [
            'offer_id' => $offer->getId(),
            'merchant_id' => $merchant->getId()?->toRfc4122(),
            'customer_count' => count($customers),
        ]);

        $hasFailure = false;
        foreach ($customers as $customer) {
            if (!$this->canReceivePromotionalNotification($customer, $merchant)) {
                $this->logger->debug('promotional_offer.dispatch.ending_offer_customer_skipped_preference', [
                    'offer_id' => $offer->getId(),
                    'merchant_id' => $merchant->getId()?->toRfc4122(),
                    'customer_id' => $customer->getId(),
                ]);

                continue;
            }

            $sent = $this->notificationService->notifyPromotionalOfferEndingSoon($customer, $merchant, $offer);
            if (!$sent) {
                $hasFailure = true;

                $this->logger->warning('promotional_offer.dispatch.ending_offer_customer_notification_failed', [
                    'offer_id' => $offer->getId(),
                    'merchant_id' => $merchant->getId()?->toRfc4122(),
                    'customer_id' => $customer->getId(),
                ]);
            }

            $this->logger->debug('promotional_offer.dispatch.ending_offer_customer_notified', [
                'offer_id' => $offer->getId(),
                'merchant_id' => $merchant->getId()?->toRfc4122(),
                'customer_id' => $customer->getId(),
            ]);
        }

        return !$hasFailure;
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