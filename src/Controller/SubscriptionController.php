<?php

namespace App\Controller;

use App\Entity\Merchant;
use App\Repository\PlanRepository;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\Stripe;
use Stripe\Checkout\Session;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class SubscriptionController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PlanRepository $planRepository,
    ) {
    }

    #[Route('/api/subscription/checkout', name: 'create_checkout_session', methods: ['POST'])]
    public function createCheckoutSession(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }
        $merchant = $user->getMerchant();
        if (!$merchant) {
            return new JsonResponse(['error' => 'Merchant not found'], 404);
        }
        $data = json_decode($request->getContent(), true);
        $plan = null;
        if (isset($data['plan_id'])) {
            $plan = $this->planRepository->find($data['plan_id']);
        } elseif (isset($data['plan_slug'])) {
            $plan = $this->planRepository->findBySlug($data['plan_slug']);
        }
        if (!$plan) {
            return new JsonResponse(['error' => 'plan_id or plan_slug required'], 400);
        }
        if ($plan->getSlug() === 'free') {
            $merchant->setPlan($plan);
            $merchant->setSubscriptionStatus('active');
            $this->entityManager->flush();
            return new JsonResponse(['plan' => $plan->getSlug(), 'status' => 'active']);
        }
        $stripePriceId = $plan->getStripePriceId();
        if (!$stripePriceId) {
            return new JsonResponse(['error' => 'This plan is not yet available for purchase'], 503);
        }
        try {
            Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY']);
            $stripeCustomerId = $merchant->getStripeCustomerId();
            if (!$stripeCustomerId) {
                $customer = \Stripe\Customer::create(['email' => $merchant->getEmail(), 'metadata' => ['merchant_id' => (string) $merchant->getId()]]);
                $stripeCustomerId = $customer->id;
                $merchant->setStripeCustomerId($stripeCustomerId);
                $this->entityManager->flush();
            }
            $session = Session::create(['payment_method_types' => ['card'], 'customer' => $stripeCustomerId, 'line_items' => [['price' => $stripePriceId, 'quantity' => 1]], 'mode' => 'subscription', 'success_url' => $_ENV['STRIPE_SUCCESS_URL'] ?? 'http://localhost:5173/subscription?session_id={CHECKOUT_SESSION_ID}', 'cancel_url' => $_ENV['STRIPE_CANCEL_URL'] ?? 'http://localhost:5173/subscription?canceled=true', 'metadata' => ['merchant_id' => (string) $merchant->getId(), 'plan_id' => $plan->getId()]]);
            return new JsonResponse(['sessionId' => $session->id, 'checkoutUrl' => $session->url]);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Failed to create checkout session: ' . $e->getMessage()], 500);
        }
    }

    #[Route('/api/subscription/webhook', name: 'handle_stripe_webhook', methods: ['POST'])]
    public function handleWebhook(Request $request): JsonResponse
    {
        try {
            Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY']);
            $event = \Stripe\Webhook::constructEvent($request->getContent(), $request->headers->get('stripe-signature'), $_ENV['STRIPE_WEBHOOK_SECRET']);
            switch ($event->type) {
                case 'checkout.session.completed': $this->handleCheckoutSessionCompleted($event->data->object); break;
                case 'customer.subscription.updated': $this->handleSubscriptionUpdated($event->data->object); break;
                case 'customer.subscription.deleted': $this->handleSubscriptionDeleted($event->data->object); break;
                case 'invoice.payment_failed': $this->handlePaymentFailed($event->data->object); break;
            }
            return new JsonResponse(['received' => true], 200);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            return new JsonResponse(['error' => 'Invalid signature'], 403);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Webhook error: ' . $e->getMessage()], 500);
        }
    }

    private function handleCheckoutSessionCompleted($session): void
    {
        $merchant = $this->entityManager->getRepository(Merchant::class)->findOneBy(['stripeCustomerId' => $session->customer]);
        if (!$merchant) return;
        $planId = $session->metadata->plan_id ?? null;
        if ($planId) { $plan = $this->planRepository->find($planId); if ($plan) $merchant->setPlan($plan); }
        $merchant->setSubscriptionStatus('active');
        $this->entityManager->flush();
    }

    private function handleSubscriptionUpdated($subscription): void
    {
        $merchant = $this->entityManager->getRepository(Merchant::class)->findOneBy(['stripeCustomerId' => $subscription->customer]);
        if (!$merchant) return;
        $merchant->setSubscriptionStatus($subscription->status === 'active' ? 'active' : 'inactive');
        $this->entityManager->flush();
    }

    private function handleSubscriptionDeleted($subscription): void
    {
        $merchant = $this->entityManager->getRepository(Merchant::class)->findOneBy(['stripeCustomerId' => $subscription->customer]);
        if (!$merchant) return;
        $freePlan = $this->planRepository->findBySlug('free');
        if ($freePlan) $merchant->setPlan($freePlan);
        $merchant->setSubscriptionStatus('canceled');
        $this->entityManager->flush();
    }

    private function handlePaymentFailed($invoice): void
    {
        $merchant = $this->entityManager->getRepository(Merchant::class)->findOneBy(['stripeCustomerId' => $invoice->customer]);
        if (!$merchant) return;
        $merchant->setSubscriptionStatus('suspended');
        $this->entityManager->flush();
    }
}
