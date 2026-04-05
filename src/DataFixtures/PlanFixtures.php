<?php

namespace App\DataFixtures;

use App\Entity\Plan;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class PlanFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $plans = [
            [
                'slug' => 'free',
                'name' => 'Gratuit',
                'price_monthly' => 0,
                'max_customers' => 50,
                'max_programs' => 1,
                'has_wallet_integration' => false,
                'has_push_notifications' => false,
                'has_advanced_stats' => false,
                'is_active' => true,
                'stripe_price_id' => null,
            ],
            [
                'slug' => 'standard',
                'name' => 'Standard',
                'price_monthly' => 1900,
                'max_customers' => 500,
                'max_programs' => 5,
                'has_wallet_integration' => false,
                'has_push_notifications' => false,
                'has_advanced_stats' => false,
                'is_active' => true,
                // 'stripe_price_id' => 'price_1TIpbqCOlIXBpVLDLspvPmb6', prod
                'stripe_price_id' => 'price_1TIqZJCS4y2pRnsFL3MgJRgW',
            ],
            [
                'slug' => 'premium',
                'name' => 'Premium',
                'price_monthly' => 2900,
                'max_customers' => -1,
                'max_programs' => -1,
                'has_wallet_integration' => true,
                'has_push_notifications' => true,
                'has_advanced_stats' => true,
                'is_active' => true,
                // 'stripe_price_id' => 'price_1TIpeOCOlIXBpVLDVLjZ0RCd',prod
                'stripe_price_id' => 'price_1TIqgfCS4y2pRnsFO6Xk8bdz',
            ],
        ];

        foreach ($plans as $data) {
            $plan = new Plan();
            $plan->setSlug($data['slug']);
            $plan->setName($data['name']);
            $plan->setPriceMonthly($data['price_monthly']);
            $plan->setMaxCustomers($data['max_customers']);
            $plan->setMaxPrograms($data['max_programs']);
            $plan->setHasWalletIntegration($data['has_wallet_integration']);
            $plan->setHasPushNotifications($data['has_push_notifications']);
            $plan->setHasAdvancedStats($data['has_advanced_stats']);
            $plan->setIsActive($data['is_active']);
            $plan->setStripePriceId($data['stripe_price_id']);

            $manager->persist($plan);
            $this->addReference('plan_' . $data['slug'], $plan, \App\Entity\Plan::class);
        }

        $manager->flush();
    }
}
