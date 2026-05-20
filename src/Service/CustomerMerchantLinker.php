<?php

namespace App\Service;

use App\Entity\Customer;
use App\Entity\CustomerMerchantNotificationPreference;
use App\Entity\Merchant;
use App\Exception\CustomerMerchantLimitReachedException;
use App\Repository\CustomerRepository;
use App\Repository\CustomerMerchantNotificationPreferenceRepository;
use Doctrine\ORM\EntityManagerInterface;

class CustomerMerchantLinker
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CustomerRepository $customerRepository,
        private readonly CustomerMerchantNotificationPreferenceRepository $preferenceRepository,
    ) {
    }

    public function assertMerchantCanAcceptCustomer(Merchant $merchant): void
    {
        // Compte gratuit accordé par un super-admin : aucune limite ne s'applique.
        if ($merchant->isFreeAccount()) {
            return;
        }

        $plan = $merchant->getPlan();
        if ($plan === null || $plan->getMaxCustomers() < 0) {
            return;
        }

        $currentCustomers = $this->customerRepository->countByMerchant($merchant);
        $maxCustomers = (int) $plan->getMaxCustomers();

        if ($currentCustomers >= $maxCustomers) {
            throw new CustomerMerchantLimitReachedException(
                merchantId: $merchant->getId()?->toRfc4122() ?? 'n/a',
                merchantName: (string) ($merchant->getCompanyName() ?? 'merchant'),
                currentCustomers: $currentCustomers,
                maxCustomers: $maxCustomers,
            );
        }
    }

    /**
     * Links a customer to a merchant and ensures required defaults.
     *
     * Returns true when the customer was newly linked in the customer_merchants relation.
     */
    public function link(Customer $customer, Merchant $merchant): bool
    {
        $isNewLink = !$customer->getMerchants()->contains($merchant);

        if ($isNewLink) {
            $this->assertMerchantCanAcceptCustomer($merchant);
        }

        if ($isNewLink) {
            $customer->addMerchant($merchant);
        }

        if ($customer->getMerchant() === null) {
            $customer->setMerchant($merchant);
        }

        $existingPreference = $this->preferenceRepository->findOneByCustomerAndMerchant($customer, $merchant);
        if (!$existingPreference instanceof CustomerMerchantNotificationPreference) {
            $preference = new CustomerMerchantNotificationPreference();
            $preference->setCustomer($customer);
            $preference->setMerchant($merchant);
            $preference->setEnabled(true);

            $this->entityManager->persist($preference);
        }

        $this->entityManager->persist($customer);

        return $isNewLink;
    }
}
