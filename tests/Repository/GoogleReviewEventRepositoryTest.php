<?php

namespace App\Tests\Repository;

use App\Entity\Customer;
use App\Entity\Merchant;
use App\Entity\GoogleReviewEvent;
use App\Entity\MerchantGoogleReviewModule;
use App\Entity\User;
use App\Enum\GoogleReviewEventType;
use App\Repository\GoogleReviewEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class GoogleReviewEventRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private GoogleReviewEventRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repository = $this->em->getRepository(GoogleReviewEvent::class);
    }

    public function testFindLatestOutboundClickedForCustomerAndMerchant(): void
    {
        $suffix = bin2hex(random_bytes(6));
        $merchantEmail = sprintf('merchant-repo-test-%s@example.com', $suffix);
        $customerEmail = sprintf('customer-repo-test-%s@example.com', $suffix);

        $merchantUser = (new User())
            ->setEmail($merchantEmail)
            ->setRoles(['ROLE_MERCHANT']);

        $merchant = (new Merchant())
            ->setCompanyName('Merchant Repo Test')
            ->setEmail($merchantEmail)
            ->setUser($merchantUser);

        $customer = (new Customer())
            ->setName('Customer Repo Test')
            ->setEmail($customerEmail);

        $module = (new MerchantGoogleReviewModule())
            ->setMerchant($merchant)
            ->setIsEnabled(true)
            ->setGoogleReviewUrl('https://search.google.com/local/writereview?placeid=test-repo')
            ->setRewardOptions([
                ['id' => 'repo-1', 'label' => 'Cafe offert', 'description' => null, 'active' => true, 'order' => 1],
            ]);

        $this->em->persist($merchantUser);
        $this->em->persist($customer);
        $this->em->persist($merchant);
        $this->em->persist($module);
        $this->em->flush();

        $event = new GoogleReviewEvent();
        $event->setCustomer($customer);
        $event->setMerchant($merchant);
        $event->setModule($module);
        $event->setEventType(GoogleReviewEventType::OUTBOUND_CLICKED);
        $event->setCreatedAt(new \DateTimeImmutable('-1 day'));
        $this->em->persist($event);
        $this->em->flush();

        $found = $this->repository->findLatestOutboundClickedForCustomerAndMerchant($customer, $merchant);
        $this->assertNotNull($found);
        $this->assertEquals(GoogleReviewEventType::OUTBOUND_CLICKED, $found->getEventType());
        $this->assertEquals($customer->getId(), $found->getCustomer()->getId());
        $this->assertEquals($merchant->getId(), $found->getMerchant()->getId());
    }
}
