<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Customer;
use App\Entity\Merchant;
use App\Entity\Plan;
use App\Exception\CustomerMerchantLimitReachedException;
use App\Repository\CustomerMerchantNotificationPreferenceRepository;
use App\Repository\CustomerRepository;
use App\Service\CustomerMerchantLinker;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class CustomerMerchantLinkerTest extends TestCase
{
    public function testAssertMerchantCanAcceptCustomerThrowsWhenLimitReached(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $customerRepository = $this->createMock(CustomerRepository::class);
        $preferenceRepository = $this->createMock(CustomerMerchantNotificationPreferenceRepository::class);

        $plan = new Plan();
        $plan->setSlug('free');
        $plan->setName('Gratuit');
        $plan->setPriceMonthly(0);
        $plan->setMaxCustomers(1);
        $plan->setMaxPrograms(1);
        $plan->setHasWalletIntegration(false);
        $plan->setHasPushNotifications(false);
        $plan->setHasAdvancedStats(false);
        $plan->setIsActive(true);
        $plan->setStripePriceId('price_free');

        $merchant = new Merchant();
        $merchant->setCompanyName('Merchant limit');
        $merchant->setPlan($plan);

        $customerRepository
            ->expects(self::once())
            ->method('countByMerchant')
            ->with(self::identicalTo($merchant))
            ->willReturn(1);

        $linker = new CustomerMerchantLinker($entityManager, $customerRepository, $preferenceRepository);

        $this->expectException(CustomerMerchantLimitReachedException::class);
        $this->expectExceptionMessage('customer_limit_reached');

        $linker->assertMerchantCanAcceptCustomer($merchant);
    }

    public function testLinkDoesNotAttachCustomerWhenLimitReached(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $customerRepository = $this->createMock(CustomerRepository::class);
        $preferenceRepository = $this->createMock(CustomerMerchantNotificationPreferenceRepository::class);

        $plan = new Plan();
        $plan->setSlug('free');
        $plan->setName('Gratuit');
        $plan->setPriceMonthly(0);
        $plan->setMaxCustomers(1);
        $plan->setMaxPrograms(1);
        $plan->setHasWalletIntegration(false);
        $plan->setHasPushNotifications(false);
        $plan->setHasAdvancedStats(false);
        $plan->setIsActive(true);
        $plan->setStripePriceId('price_free');

        $merchant = new Merchant();
        $merchant->setCompanyName('Merchant limit');
        $merchant->setPlan($plan);

        $customer = (new Customer())->setName('Alice')->setEmail('alice@example.com');

        $customerRepository
            ->expects(self::once())
            ->method('countByMerchant')
            ->with(self::identicalTo($merchant))
            ->willReturn(1);

        $entityManager->expects(self::never())->method('persist');

        $linker = new CustomerMerchantLinker($entityManager, $customerRepository, $preferenceRepository);

        $this->expectException(CustomerMerchantLimitReachedException::class);
        $linker->link($customer, $merchant);
    }
}
