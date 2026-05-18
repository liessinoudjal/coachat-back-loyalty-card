<?php

namespace App\Tests\Service;

use App\Entity\Customer;
use App\Entity\Merchant;
use App\Entity\GoogleReviewEvent;
use App\Entity\MerchantGoogleReviewModule;
use App\Entity\User;
use App\Enum\GoogleReviewEventType;
use App\Enum\NotificationType;
use App\Service\EmailNotificationStrategy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class EmailNotificationStrategyTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private EmailNotificationStrategy $strategy;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->strategy = static::getContainer()->get(EmailNotificationStrategy::class);
    }

    public function testGoogleReviewInviteSuppressedIfOutboundClicked(): void
    {
        $suffix = bin2hex(random_bytes(6));
        $merchantEmail = sprintf('merchant-email-test-%s@example.com', $suffix);
        $customerEmail = sprintf('customer-email-test-%s@example.com', $suffix);

        $merchantUser = (new User())
            ->setEmail($merchantEmail)
            ->setRoles(['ROLE_MERCHANT']);

        $merchant = (new Merchant())
            ->setCompanyName('Merchant Email Test')
            ->setEmail($merchantEmail)
            ->setUser($merchantUser);

        $customer = (new Customer())
            ->setName('Customer Email Test')
            ->setEmail($customerEmail);

        $moduleUrl = 'https://search.google.com/local/writereview?placeid=test-email';
        $module = (new MerchantGoogleReviewModule())
            ->setMerchant($merchant)
            ->setIsEnabled(true)
            ->setGoogleReviewUrl($moduleUrl)
            ->setRewardOptions([
                ['id' => 'email-1', 'label' => 'Dessert offert', 'description' => null, 'active' => true, 'order' => 1],
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
        $event->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($event);
        $this->em->flush();

        $reflection = new \ReflectionClass($this->strategy);
        $method = $reflection->getMethod('buildEmailData');
        $method->setAccessible(true);
        $result = $method->invoke($this->strategy, $merchant, $customer, NotificationType::CUSTOMER_SIGNUP, []);

        $this->assertArrayHasKey('html', $result);
        $this->assertStringNotContainsString($moduleUrl, $result['html']);
    }
}
