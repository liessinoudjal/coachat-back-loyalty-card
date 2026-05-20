<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Customer;
use App\Entity\CustomerMerchantNotificationPreference;
use App\Entity\Merchant;
use App\Entity\PromotionalOffer;
use App\Repository\CustomerMerchantNotificationPreferenceRepository;
use App\Repository\CustomerRepository;
use App\Repository\PromotionalOfferRepository;
use App\Service\NotificationService;
use App\Service\PromotionalOfferNotificationDispatcher;
use App\Service\SignupAlertMailer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class PromotionalOfferNotificationDispatcherTest extends TestCase
{
    public function testDispatchSendsStartAndEndingSoonNotificationsAndMarksOffers(): void
    {
        $offerRepository = $this->createMock(PromotionalOfferRepository::class);
        $customerRepository = $this->createMock(CustomerRepository::class);
        $preferenceRepository = $this->createMock(CustomerMerchantNotificationPreferenceRepository::class);
        $notificationService = $this->createMock(NotificationService::class);
        $signupAlertMailer = $this->createMock(SignupAlertMailer::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $today = new \DateTimeImmutable('2026-05-09');

        $merchantStart = (new Merchant())->setCompanyName('Merchant Start');
        $merchantEnding = (new Merchant())->setCompanyName('Merchant Ending');

        $startOffer = (new PromotionalOffer())
            ->setMerchant($merchantStart)
            ->setTitle('Start Offer')
            ->setDescription('Start Desc')
            ->setStartsOn($today)
            ->setEndsOn($today->modify('+10 days'));

        $endingOffer = (new PromotionalOffer())
            ->setMerchant($merchantEnding)
            ->setTitle('Ending Offer')
            ->setDescription('Ending Desc')
            ->setStartsOn($today->modify('-5 days'))
            ->setEndsOn($today->modify('+2 days'));

        $eligibleStartCustomer = (new Customer())->setName('A')->setEmail('a@example.com');
        $disabledStartCustomer = (new Customer())->setName('B')->setEmail('b@example.com');
        $eligibleEndingCustomer = (new Customer())->setName('C')->setEmail('c@example.com');

        $offerRepository
            ->expects(self::once())
            ->method('findFlashOffersForDayBefore')
            ->with($today->modify('+1 day'))
            ->willReturn([]);

        $offerRepository
            ->expects(self::once())
            ->method('findStartingOnDate')
            ->with($today)
            ->willReturn([$startOffer]);

        $offerRepository
            ->expects(self::once())
            ->method('findEndingOnDate')
            ->with($today->modify('+2 days'))
            ->willReturn([$endingOffer]);

        $customerRepository
            ->expects(self::exactly(2))
            ->method('findByMerchant')
            ->willReturnCallback(static function (Merchant $merchant) use (
                $merchantStart,
                $merchantEnding,
                $eligibleStartCustomer,
                $disabledStartCustomer,
                $eligibleEndingCustomer,
            ): array {
                if ($merchant === $merchantStart) {
                    return [$eligibleStartCustomer, $disabledStartCustomer];
                }

                if ($merchant === $merchantEnding) {
                    return [$eligibleEndingCustomer];
                }

                return [];
            });

        $disabledPreference = (new CustomerMerchantNotificationPreference())
            ->setEnabled(true)
            ->setPromotionalOffersEnabled(false);

        $preferenceRepository
            ->expects(self::exactly(3))
            ->method('findOneByCustomerAndMerchant')
            ->willReturnCallback(static function (Customer $customer, Merchant $merchant) use (
                $disabledStartCustomer,
                $merchantStart,
                $disabledPreference,
            ): ?CustomerMerchantNotificationPreference {
                if ($customer === $disabledStartCustomer && $merchant === $merchantStart) {
                    return $disabledPreference;
                }

                return null;
            });

        $notificationService
            ->expects(self::once())
            ->method('notifyPromotionalOfferStarts')
            ->with($eligibleStartCustomer, $merchantStart, $startOffer)
            ->willReturn(true);

        $notificationService
            ->expects(self::once())
            ->method('notifyPromotionalOfferEndingSoon')
            ->with($eligibleEndingCustomer, $merchantEnding, $endingOffer)
            ->willReturn(true);

        $entityManager->expects(self::once())->method('flush');

        $dispatcher = new PromotionalOfferNotificationDispatcher(
            $offerRepository,
            $customerRepository,
            $preferenceRepository,
            $notificationService,
            $signupAlertMailer,
            $entityManager,
            new NullLogger(),
        );

        $result = $dispatcher->dispatch($today);

        self::assertSame(1, $result['start_notifications_sent_for_offers']);
        self::assertSame(1, $result['ending_soon_notifications_sent_for_offers']);
        self::assertNotNull($startOffer->getStartNotificationSentAt());
        self::assertNotNull($endingOffer->getEndingSoonNotificationSentAt());
    }
}
