<?php

namespace App\Service;

use App\Entity\Customer;
use App\Entity\CustomerMerchantNotificationPreference;
use App\Entity\Merchant;
use App\Repository\CustomerMerchantNotificationPreferenceRepository;
use Doctrine\ORM\EntityManagerInterface;

class CustomerMerchantLinker
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CustomerMerchantNotificationPreferenceRepository $preferenceRepository,
    ) {
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
