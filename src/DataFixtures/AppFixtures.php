<?php

namespace App\DataFixtures;

use App\Entity\Merchant;
use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class AppFixtures extends Fixture implements DependentFixtureInterface
{
    public function getDependencies(): array
    {
        return [PlanFixtures::class, MerchantEstablishmentTypeFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        $user = new User();
        $user->setEmail('liess.inoudjal@gmail.com');
        $user->setName('Liess Inoudjal');
        $user->setRoles(['ROLE_USER']);

        $manager->persist($user);

        /** @var \App\Entity\Plan $freePlan */
        $freePlan = $this->getReference('plan_free', \App\Entity\Plan::class);

        $merchant = new Merchant();
        $merchant->setCompanyName('LAKARTE');
        $merchant->setEmail('liess.inoudjal@gmail.com');
        $merchant->setSubscriptionStatus('trial');
        $merchant->setTrialEndsAt((new \DateTime())->add(new \DateInterval('P30D')));
        $merchant->setPlan($freePlan);
        $merchant->setUser($user);
        $merchant->setAddress('3 rue des carmes');
        $merchant->setCity('Orléans');
        $merchant->setPostalCode('45000');
        $merchant->setEstablishmentType($this->getReference('merchant_establishment_type_restaurant', \App\Entity\MerchantEstablishmentType::class));
        $merchant->setIsFreeAccount(true);


        $manager->persist($merchant);

        $manager->flush();

        $this->addReference('merchant_main', $merchant);
    }
}
