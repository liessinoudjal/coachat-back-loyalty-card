<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Customer;
use App\Entity\CustomerMerchantNotificationPreference;
use App\Entity\LoyaltyCard;
use App\Entity\LoyaltyProgram;
use App\Entity\Merchant;
use App\Entity\NotificationLog;
use App\Entity\Plan;
use App\Entity\PromotionalOffer;
use App\Entity\Reward;
use App\Entity\Transaction;
use App\Enum\LoyaltyProgramType;
use App\Enum\NotificationType;
use App\Repository\CustomerMerchantNotificationPreferenceRepository;
use App\Service\EmailNotificationStrategy;
use App\Service\NotificationService;
use App\Service\PushNotificationStrategy;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class NotificationServiceTest extends TestCase
{
    public function testNotifyPointsAddedUsesEmailWhenPushIsDisabled(): void
    {
        $emailStrategy = $this->createMock(EmailNotificationStrategy::class);
        $pushStrategy = $this->createMock(PushNotificationStrategy::class);
        $preferenceRepository = $this->createMock(CustomerMerchantNotificationPreferenceRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $transaction = $this->buildTransaction(false);
        $customer = $transaction->getLoyaltyCard()?->getCustomer();
        $merchant = $transaction->getMerchant();

        $preferenceRepository
            ->expects(self::once())
            ->method('findOneByCustomerAndMerchant')
            ->with($customer, $merchant)
            ->willReturn((new CustomerMerchantNotificationPreference())->setEnabled(true));

        $emailStrategy
            ->expects(self::once())
            ->method('send')
            ->with(
                $merchant,
                $customer,
                NotificationType::POINTS_ADDED,
                self::callback(static function (array $context): bool {
                    return $context['unit_label'] === 'points'
                        && $context['value_added'] === 3
                        && $context['dashboard_url'] === 'https://front.example.com';
                }),
            );

        $pushStrategy->expects(self::never())->method('send');

        $this->expectPersistedNotificationLog($entityManager, 1);

        $service = new NotificationService(
            $emailStrategy,
            $pushStrategy,
            $preferenceRepository,
            $entityManager,
            new NullLogger(),
            'https://front.example.com',
        );

        $service->notifyPointsAdded($transaction);
    }

    public function testNotifyCustomerSignupUsesEmailWhenPushIsDisabled(): void
    {
        $emailStrategy = $this->createMock(EmailNotificationStrategy::class);
        $pushStrategy = $this->createMock(PushNotificationStrategy::class);
        $preferenceRepository = $this->createMock(CustomerMerchantNotificationPreferenceRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $merchant = $this->buildMerchant(false);
        $customer = (new Customer())
            ->setName('Alice')
            ->setEmail('alice@example.com');

        $preferenceRepository
            ->expects(self::once())
            ->method('findOneByCustomerAndMerchant')
            ->with($customer, $merchant)
            ->willReturn((new CustomerMerchantNotificationPreference())->setEnabled(true));

        $emailStrategy
            ->expects(self::once())
            ->method('send')
            ->with(
                $merchant,
                $customer,
                NotificationType::CUSTOMER_SIGNUP,
                self::callback(static function (array $context): bool {
                    return $context['dashboard_url'] === 'https://front.example.com';
                }),
            );

        $pushStrategy->expects(self::never())->method('send');

        $this->expectPersistedNotificationLog($entityManager, 1);

        $service = new NotificationService(
            $emailStrategy,
            $pushStrategy,
            $preferenceRepository,
            $entityManager,
            new NullLogger(),
            'https://front.example.com',
        );

        $service->notifyCustomerSignup($customer, $merchant);
    }

    public function testNotifyCardCreatedUsesEmailWhenPushIsDisabled(): void
    {
        $emailStrategy = $this->createMock(EmailNotificationStrategy::class);
        $pushStrategy = $this->createMock(PushNotificationStrategy::class);
        $preferenceRepository = $this->createMock(CustomerMerchantNotificationPreferenceRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $card = $this->buildCard(false);
        $customer = $card->getCustomer();
        $merchant = $card->getMerchant();

        $preferenceRepository
            ->expects(self::once())
            ->method('findOneByCustomerAndMerchant')
            ->with($customer, $merchant)
            ->willReturn((new CustomerMerchantNotificationPreference())->setEnabled(true));

        $emailStrategy
            ->expects(self::once())
            ->method('send')
            ->with(
                $merchant,
                $customer,
                NotificationType::CARD_CREATED,
                self::callback(static function (array $context): bool {
                    return $context['program_name'] === 'Programme points'
                        && $context['target_value'] === 10
                        && $context['unit_label'] === 'points'
                        && $context['dashboard_url'] === 'https://front.example.com';
                }),
            );

        $pushStrategy->expects(self::never())->method('send');

        $this->expectPersistedNotificationLog($entityManager, 1);

        $service = new NotificationService(
            $emailStrategy,
            $pushStrategy,
            $preferenceRepository,
            $entityManager,
            new NullLogger(),
            'https://front.example.com',
        );

        $service->notifyCardCreated($card);
    }

    public function testNotifyCardCompletedUsesEmailWhenPushIsDisabled(): void
    {
        $emailStrategy = $this->createMock(EmailNotificationStrategy::class);
        $pushStrategy = $this->createMock(PushNotificationStrategy::class);
        $preferenceRepository = $this->createMock(CustomerMerchantNotificationPreferenceRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $reward = $this->buildReward(false);
        $customer = $reward->getCustomer();
        $merchant = $reward->getMerchant();

        $preferenceRepository
            ->expects(self::once())
            ->method('findOneByCustomerAndMerchant')
            ->with($customer, $merchant)
            ->willReturn((new CustomerMerchantNotificationPreference())->setEnabled(true));

        $emailStrategy
            ->expects(self::once())
            ->method('send')
            ->with(
                $merchant,
                $customer,
                NotificationType::CARD_COMPLETED,
                self::callback(static function (array $context): bool {
                    return $context['reward_description'] === 'Cafe offert'
                        && $context['program_name'] === 'Programme points'
                        && $context['dashboard_url'] === 'https://front.example.com';
                }),
            );

        $pushStrategy->expects(self::never())->method('send');

        $this->expectPersistedNotificationLog($entityManager, 1);

        $service = new NotificationService(
            $emailStrategy,
            $pushStrategy,
            $preferenceRepository,
            $entityManager,
            new NullLogger(),
            'https://front.example.com',
        );

        $service->notifyCardCompleted($reward);
    }

    public function testNotifyRewardClaimedUsesPushWhenPlanSupportsIt(): void
    {
        $emailStrategy = $this->createMock(EmailNotificationStrategy::class);
        $pushStrategy = $this->createMock(PushNotificationStrategy::class);
        $preferenceRepository = $this->createMock(CustomerMerchantNotificationPreferenceRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $reward = $this->buildReward(true);
        $customer = $reward->getCustomer();
        $merchant = $reward->getMerchant();

        $preferenceRepository
            ->expects(self::once())
            ->method('findOneByCustomerAndMerchant')
            ->with($customer, $merchant)
            ->willReturn((new CustomerMerchantNotificationPreference())->setEnabled(true));

        $emailStrategy->expects(self::never())->method('send');
        $pushStrategy
            ->expects(self::once())
            ->method('send')
            ->with(
                $merchant,
                $customer,
                NotificationType::REWARD_CLAIMED,
                self::callback(static function (array $context): bool {
                    return $context['reward_description'] === 'Cafe offert'
                        && $context['dashboard_url'] === 'https://front.example.com';
                }),
            );

        $this->expectPersistedNotificationLog($entityManager, 1);

        $service = new NotificationService(
            $emailStrategy,
            $pushStrategy,
            $preferenceRepository,
            $entityManager,
            new NullLogger(),
            'https://front.example.com',
        );

        $service->notifyRewardClaimed($reward);
    }

    public function testNotifyPointsAddedSkipsWhenCustomerPreferenceIsDisabled(): void
    {
        $emailStrategy = $this->createMock(EmailNotificationStrategy::class);
        $pushStrategy = $this->createMock(PushNotificationStrategy::class);
        $preferenceRepository = $this->createMock(CustomerMerchantNotificationPreferenceRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $transaction = $this->buildTransaction(false);
        $customer = $transaction->getLoyaltyCard()?->getCustomer();
        $merchant = $transaction->getMerchant();

        $preferenceRepository
            ->expects(self::once())
            ->method('findOneByCustomerAndMerchant')
            ->with($customer, $merchant)
            ->willReturn((new CustomerMerchantNotificationPreference())->setEnabled(false));

        $emailStrategy->expects(self::never())->method('send');
        $pushStrategy->expects(self::never())->method('send');
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $service = new NotificationService(
            $emailStrategy,
            $pushStrategy,
            $preferenceRepository,
            $entityManager,
            new NullLogger(),
            'https://front.example.com',
        );

        $service->notifyPointsAdded($transaction);
    }

    public function testNotifyPromotionalOfferStartsUsesEmailWhenEnabled(): void
    {
        $emailStrategy = $this->createMock(EmailNotificationStrategy::class);
        $pushStrategy = $this->createMock(PushNotificationStrategy::class);
        $preferenceRepository = $this->createMock(CustomerMerchantNotificationPreferenceRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $merchant = $this->buildMerchant(false);
        $customer = (new Customer())
            ->setName('Alice')
            ->setEmail('alice@example.com');
        $offer = (new PromotionalOffer())
            ->setMerchant($merchant)
            ->setTitle('Bon plan de la semaine')
            ->setDescription('Description du bon plan')
            ->setStartsOn(new \DateTimeImmutable('2026-05-09'))
            ->setEndsOn(new \DateTimeImmutable('2026-05-12'));

        $preferenceRepository
            ->expects(self::once())
            ->method('findOneByCustomerAndMerchant')
            ->with($customer, $merchant)
            ->willReturn((new CustomerMerchantNotificationPreference())->setEnabled(true)->setPromotionalOffersEnabled(true));

        $emailStrategy
            ->expects(self::once())
            ->method('send')
            ->with(
                $merchant,
                $customer,
                NotificationType::PROMOTIONAL_OFFER_STARTS,
                self::callback(static function (array $context): bool {
                    return $context['offer_title'] === 'Bon plan de la semaine'
                        && $context['offer_starts_on'] === '2026-05-09'
                        && $context['offer_ends_on'] === '2026-05-12'
                        && $context['dashboard_url'] === 'https://front.example.com';
                }),
            );

        $pushStrategy->expects(self::never())->method('send');

        $this->expectPersistedNotificationLog($entityManager, 1);

        $service = new NotificationService(
            $emailStrategy,
            $pushStrategy,
            $preferenceRepository,
            $entityManager,
            new NullLogger(),
            'https://front.example.com',
        );

        $service->notifyPromotionalOfferStarts($customer, $merchant, $offer);
    }

    public function testNotifyPromotionalOfferStartsSkipsWhenPromotionalPreferenceDisabled(): void
    {
        $emailStrategy = $this->createMock(EmailNotificationStrategy::class);
        $pushStrategy = $this->createMock(PushNotificationStrategy::class);
        $preferenceRepository = $this->createMock(CustomerMerchantNotificationPreferenceRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $merchant = $this->buildMerchant(false);
        $customer = (new Customer())
            ->setName('Alice')
            ->setEmail('alice@example.com');
        $offer = (new PromotionalOffer())
            ->setMerchant($merchant)
            ->setTitle('Bon plan de la semaine')
            ->setDescription('Description du bon plan')
            ->setStartsOn(new \DateTimeImmutable('2026-05-09'))
            ->setEndsOn(new \DateTimeImmutable('2026-05-12'));

        $preferenceRepository
            ->expects(self::once())
            ->method('findOneByCustomerAndMerchant')
            ->with($customer, $merchant)
            ->willReturn((new CustomerMerchantNotificationPreference())->setEnabled(true)->setPromotionalOffersEnabled(false));

        $emailStrategy->expects(self::never())->method('send');
        $pushStrategy->expects(self::never())->method('send');
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $service = new NotificationService(
            $emailStrategy,
            $pushStrategy,
            $preferenceRepository,
            $entityManager,
            new NullLogger(),
            'https://front.example.com',
        );

        $service->notifyPromotionalOfferStarts($customer, $merchant, $offer);
    }

    public function testNotifyPromotionalOfferStartsIgnoresGlobalEnabledWhenPromotionalPreferenceEnabled(): void
    {
        $emailStrategy = $this->createMock(EmailNotificationStrategy::class);
        $pushStrategy = $this->createMock(PushNotificationStrategy::class);
        $preferenceRepository = $this->createMock(CustomerMerchantNotificationPreferenceRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $merchant = $this->buildMerchant(false);
        $customer = (new Customer())
            ->setName('Alice')
            ->setEmail('alice@example.com');
        $offer = (new PromotionalOffer())
            ->setMerchant($merchant)
            ->setTitle('Bon plan de la semaine')
            ->setDescription('Description du bon plan')
            ->setStartsOn(new \DateTimeImmutable('2026-05-09'))
            ->setEndsOn(new \DateTimeImmutable('2026-05-12'));

        $preferenceRepository
            ->expects(self::once())
            ->method('findOneByCustomerAndMerchant')
            ->with($customer, $merchant)
            ->willReturn((new CustomerMerchantNotificationPreference())->setEnabled(false)->setPromotionalOffersEnabled(true));

        $emailStrategy
            ->expects(self::once())
            ->method('send')
            ->with(
                $merchant,
                $customer,
                NotificationType::PROMOTIONAL_OFFER_STARTS,
                self::isType('array'),
            );

        $pushStrategy->expects(self::never())->method('send');

        $this->expectPersistedNotificationLog($entityManager, 1);

        $service = new NotificationService(
            $emailStrategy,
            $pushStrategy,
            $preferenceRepository,
            $entityManager,
            new NullLogger(),
            'https://front.example.com',
        );

        $service->notifyPromotionalOfferStarts($customer, $merchant, $offer);
    }

    private function buildTransaction(bool $pushEnabled): Transaction
    {
        $merchant = $this->buildMerchant($pushEnabled);
        $customer = (new Customer())
            ->setName('Alice')
            ->setEmail('alice@example.com');
        $program = (new LoyaltyProgram())
            ->setName('Programme points')
            ->setType(LoyaltyProgramType::POINTS)
            ->setPointsTarget(10)
            ->setPointsPerEuro(1)
            ->setMerchant($merchant);
        $card = (new LoyaltyCard())
            ->setMerchant($merchant)
            ->setCustomer($customer)
            ->setLoyaltyProgram($program)
            ->setCurrentValue(7)
            ->setTargetValue(10)
            ->setIsCompleted(false);

        return (new Transaction())
            ->setMerchant($merchant)
            ->setLoyaltyCard($card)
            ->setPointsEarned(3)
            ->setPointsRedeemed(0);
    }

    private function buildReward(bool $pushEnabled): Reward
    {
        $merchant = $this->buildMerchant($pushEnabled);
        $customer = (new Customer())
            ->setName('Alice')
            ->setEmail('alice@example.com');
        $program = (new LoyaltyProgram())
            ->setName('Programme points')
            ->setType(LoyaltyProgramType::POINTS)
            ->setPointsTarget(10)
            ->setPointsPerEuro(1)
            ->setRewardDescription('Cafe offert')
            ->setMerchant($merchant);
        $card = (new LoyaltyCard())
            ->setMerchant($merchant)
            ->setCustomer($customer)
            ->setLoyaltyProgram($program)
            ->setCurrentValue(10)
            ->setTargetValue(10)
            ->setIsCompleted(true);

        return (new Reward())
            ->setMerchant($merchant)
            ->setCustomer($customer)
            ->setLoyaltyCard($card)
            ->setLoyaltyProgram($program)
            ->setRewardDescription('Cafe offert');
    }

    private function buildCard(bool $pushEnabled): LoyaltyCard
    {
        $merchant = $this->buildMerchant($pushEnabled);
        $customer = (new Customer())
            ->setName('Alice')
            ->setEmail('alice@example.com');
        $program = (new LoyaltyProgram())
            ->setName('Programme points')
            ->setType(LoyaltyProgramType::POINTS)
            ->setPointsTarget(10)
            ->setPointsPerEuro(1)
            ->setMerchant($merchant);

        return (new LoyaltyCard())
            ->setMerchant($merchant)
            ->setCustomer($customer)
            ->setLoyaltyProgram($program)
            ->setCurrentValue(0)
            ->setTargetValue(10)
            ->setIsCompleted(false);
    }

    private function buildMerchant(bool $pushEnabled): Merchant
    {
        $plan = (new Plan())
            ->setName('Test plan')
            ->setSlug($pushEnabled ? 'premium' : 'free')
            ->setHasPushNotifications($pushEnabled);

        return (new Merchant())
            ->setCompanyName('Coffee Shop')
            ->setPlan($plan);
    }

    private function expectPersistedNotificationLog(EntityManagerInterface $entityManager, int $flushCount): void
    {
        $entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::isInstanceOf(NotificationLog::class));

        $entityManager
            ->expects(self::exactly($flushCount + 1))
            ->method('flush');
    }
}