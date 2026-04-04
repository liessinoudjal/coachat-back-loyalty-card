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
        return [PlanFixtures::class];
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
        $merchant->setCompanyName('coachat');
        $merchant->setEmail('liess.inoudjal@gmail.com');
        $merchant->setSubscriptionStatus('trial');
        $merchant->setTrialEndsAt((new \DateTime())->add(new \DateInterval('P30D')));
        $merchant->setPlan($freePlan);
        $merchant->setUser($user);

        $manager->persist($merchant);
        $manager->flush();
    }
}
