<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Merchant;
use App\Entity\Plan;
use App\Entity\User;
use App\Service\StripeSubscriptionPeriodService;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use App\Repository\PlanRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class SubscriptionControllerTest extends WebTestCase
{
    public function testStatusReturnsStripePeriodForActiveSubscription(): void
    {
        $client = static::createClient();

        $user = $this->createUser('active-subscription');
        $plan = $this->getOrCreatePlan('standard', 'Standard', 1900);
        $this->createMerchant($user, $plan, 'active', new \DateTime('2026-05-05 23:49:58', new \DateTimeZone('UTC')), 'cus_active_123');

        static::getContainer()->set(StripeSubscriptionPeriodService::class, new class() extends StripeSubscriptionPeriodService {
            public function getCurrentPeriod(Merchant $merchant): array
            {
                return [
                    'current_period_start' => '2026-04-05T23:49:58Z',
                    'current_period_end' => '2026-05-05T23:49:58Z',
                ];
            }
        });

        $client->request('GET', '/api/subscription/status', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->createJwtFor($user),
        ]);

        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('active', $payload['subscription_status']);
        self::assertSame('2026-05-05T23:49:58Z', $payload['trial_ends_at']);
        self::assertSame('2026-04-05T23:49:58Z', $payload['current_period_start']);
        self::assertSame('2026-05-05T23:49:58Z', $payload['current_period_end']);
        self::assertSame('standard', $payload['plan']['slug']);
    }

    public function testStatusReturnsStripeEndDateForCancelingSubscription(): void
    {
        $client = static::createClient();

        $user = $this->createUser('canceling-subscription');
        $plan = $this->getOrCreatePlan('standard', 'Standard', 1900);
        $this->createMerchant($user, $plan, 'canceling', null, 'cus_canceling_123');

        static::getContainer()->set(StripeSubscriptionPeriodService::class, new class() extends StripeSubscriptionPeriodService {
            public function getCurrentPeriod(Merchant $merchant): array
            {
                return [
                    'current_period_start' => '2026-04-05T23:49:58Z',
                    'current_period_end' => '2026-05-05T23:49:58Z',
                ];
            }
        });

        $client->request('GET', '/api/subscription/status', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->createJwtFor($user),
        ]);

        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('canceling', $payload['subscription_status']);
        self::assertSame('2026-05-05T23:49:58Z', $payload['current_period_end']);
    }

    public function testStatusReturnsNullDatesWithoutStripeSubscription(): void
    {
        $client = static::createClient();

        $user = $this->createUser('free-subscription');
        $plan = $this->getOrCreatePlan('free', 'Free', 0);
        $this->createMerchant($user, $plan, 'active', null, null);

        static::getContainer()->set(StripeSubscriptionPeriodService::class, new class() extends StripeSubscriptionPeriodService {
            public function getCurrentPeriod(Merchant $merchant): array
            {
                return [
                    'current_period_start' => null,
                    'current_period_end' => null,
                ];
            }
        });

        $client->request('GET', '/api/subscription/status', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->createJwtFor($user),
        ]);

        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertNull($payload['current_period_start']);
        self::assertNull($payload['current_period_end']);
        self::assertArrayHasKey('trial_ends_at', $payload);
    }

    public function testStatusKeepsTrialEndsAtForCompatibility(): void
    {
        $client = static::createClient();

        $user = $this->createUser('trial-compatibility');
        $plan = $this->getOrCreatePlan('standard', 'Standard', 1900);
        $this->createMerchant($user, $plan, 'trial', new \DateTime('2026-06-01 12:00:00', new \DateTimeZone('UTC')), 'cus_trial_123');

        static::getContainer()->set(StripeSubscriptionPeriodService::class, new class() extends StripeSubscriptionPeriodService {
            public function getCurrentPeriod(Merchant $merchant): array
            {
                return [
                    'current_period_start' => null,
                    'current_period_end' => null,
                ];
            }
        });

        $client->request('GET', '/api/subscription/status', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->createJwtFor($user),
        ]);

        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('trial', $payload['subscription_status']);
        self::assertSame('2026-06-01T12:00:00Z', $payload['trial_ends_at']);
        self::assertArrayHasKey('current_period_start', $payload);
        self::assertArrayHasKey('current_period_end', $payload);
    }

    public function testStatusReturnsPersistedPeriodDatesWhenStripeReturnsNull(): void
    {
        $client = static::createClient();

        $user = $this->createUser('persisted-period');
        $plan = $this->getOrCreatePlan('standard', 'Standard', 1900);
        $this->createMerchant(
            $user,
            $plan,
            'active',
            null,
            'cus_persisted_123',
            new \DateTime('2026-04-05 23:49:58', new \DateTimeZone('UTC')),
            new \DateTime('2026-05-05 23:49:58', new \DateTimeZone('UTC')),
        );

        static::getContainer()->set(StripeSubscriptionPeriodService::class, new class() extends StripeSubscriptionPeriodService {
            public function getCurrentPeriod(Merchant $merchant): array
            {
                return [
                    'current_period_start' => null,
                    'current_period_end' => null,
                ];
            }
        });

        $client->request('GET', '/api/subscription/status', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->createJwtFor($user),
        ]);

        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('2026-04-05T23:49:58Z', $payload['current_period_start']);
        self::assertSame('2026-05-05T23:49:58Z', $payload['current_period_end']);
    }

    private function createUser(string $suffix): User
    {
        $user = new User();
        $user->setEmail(sprintf('subscription-%s-%s@example.com', $suffix, bin2hex(random_bytes(4))));
        $user->setName('Subscription ' . $suffix);
        $user->setRoles(['ROLE_USER']);

        $em = $this->getEntityManager();
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function getOrCreatePlan(string $slug, string $name, int $priceMonthly): Plan
    {
        /** @var PlanRepository $planRepository */
        $planRepository = static::getContainer()->get(PlanRepository::class);
        $existingPlan = $planRepository->findBySlug($slug);
        if ($existingPlan instanceof Plan) {
            return $existingPlan;
        }

        $plan = new Plan();
        $plan->setSlug($slug);
        $plan->setName($name);
        $plan->setPriceMonthly($priceMonthly);
        $plan->setMaxCustomers(-1);
        $plan->setMaxPrograms(-1);
        $plan->setHasWalletIntegration(false);
        $plan->setHasPushNotifications(false);
        $plan->setHasAdvancedStats(false);
        $plan->setIsActive(true);
        $plan->setStripePriceId('price_test_' . $slug);

        $em = $this->getEntityManager();
        $em->persist($plan);
        $em->flush();

        return $plan;
    }

    private function createMerchant(
        User $user,
        Plan $plan,
        string $subscriptionStatus,
        ?\DateTimeInterface $trialEndsAt,
        ?string $stripeCustomerId,
        ?\DateTimeInterface $currentPeriodStartAt = null,
        ?\DateTimeInterface $currentPeriodEndAt = null
    ): Merchant
    {
        $merchant = new Merchant();
        $merchant->setCompanyName('Merchant ' . $user->getName());
        $merchant->setEmail($user->getEmail());
        $merchant->setSubscriptionStatus($subscriptionStatus);
        $merchant->setTrialEndsAt($trialEndsAt);
        $merchant->setStripeCustomerId($stripeCustomerId);
        $merchant->setCurrentPeriodStartAt($currentPeriodStartAt);
        $merchant->setCurrentPeriodEndAt($currentPeriodEndAt);
        $merchant->setPostalCode('75000');
        $merchant->setCity('Paris');
        $merchant->setPlan($plan);
        $merchant->setUser($user);

        $em = $this->getEntityManager();
        $em->persist($merchant);
        $em->flush();

        return $merchant;
    }

    private function createJwtFor(User $user): string
    {
        /** @var JWTTokenManagerInterface $jwtManager */
        $jwtManager = static::getContainer()->get(JWTTokenManagerInterface::class);

        return $jwtManager->create($user);
    }

    private function getEntityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        return $entityManager;
    }
}