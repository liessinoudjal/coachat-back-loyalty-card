<?php

namespace App\Service;

use App\Entity\Merchant;
use Stripe\Stripe;
use Stripe\Subscription;

class StripeSubscriptionPeriodService
{
    /**
     * @return array{current_period_start: string|null, current_period_end: string|null}
     */
    public function getCurrentPeriod(Merchant $merchant): array
    {
        $stripeCustomerId = $merchant->getStripeCustomerId();
        if (!$stripeCustomerId) {
            return $this->emptyPeriod();
        }

        try {
            Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY']);

            $subscriptions = Subscription::all([
                'customer' => $stripeCustomerId,
                'status' => 'all',
                'limit' => 10,
            ]);

            $subscription = $this->selectCurrentSubscription($subscriptions->data ?? []);
            if ($subscription === null) {
                return $this->emptyPeriod();
            }

            return [
                'current_period_start' => $this->formatStripeTimestamp($subscription->current_period_start ?? null),
                'current_period_end' => $this->formatStripeTimestamp($subscription->current_period_end ?? null),
            ];
        } catch (\Throwable) {
            return $this->emptyPeriod();
        }
    }

    /**
     * @param array<int, object> $subscriptions
     */
    private function selectCurrentSubscription(array $subscriptions): ?object
    {
        foreach ($subscriptions as $subscription) {
            if (($subscription->status ?? null) === 'active' || ($subscription->status ?? null) === 'trialing') {
                return $subscription;
            }
        }

        foreach ($subscriptions as $subscription) {
            if (($subscription->status ?? null) !== 'canceled') {
                return $subscription;
            }
        }

        return null;
    }

    private function formatStripeTimestamp(?int $timestamp): ?string
    {
        if ($timestamp === null) {
            return null;
        }

        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }

    /**
     * @return array{current_period_start: null, current_period_end: null}
     */
    private function emptyPeriod(): array
    {
        return [
            'current_period_start' => null,
            'current_period_end' => null,
        ];
    }
}