<?php

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Blocks access to dashboard data endpoints when the authenticated user
 * has not verified their email yet. Onboarding endpoints (creation of a
 * merchant profile, terms acceptance, etc.) remain accessible so that the
 * post-signup flow is not interrupted.
 */
final class EmailVerificationSubscriber implements EventSubscriberInterface
{
    /**
     * Each entry is a regex applied to the request path. A match means the
     * route is considered a "dashboard" route and requires a verified email.
     */
    private const PROTECTED_PATH_PATTERNS = [
        // Merchant dashboard data
        '#^/api/merchant/me$#',
        '#^/api/merchants/me(?:/|$)#',
        '#^/api/loyalty-programs#',
        '#^/api/loyalty-cards#',
        '#^/api/transactions#',
        '#^/api/rewards#',
        '#^/api/promotional-offers#',
        '#^/api/merchant-staff#',
        '#^/api/merchant/asset-download-events#',
        '#^/api/merchant/google-review#',
        '#^/api/wallet#',
        // Customer dashboard data
        '#^/api/customers/me/bootstrap$#',
        '#^/api/customers/me/cards#',
        '#^/api/customers/me/rewards#',
        '#^/api/customers/me/promotional-offers#',
        '#^/api/customers/me/available-programs#',
        '#^/api/customers/me/notification-preferences#',
    ];

    public function __construct(
        private readonly Security $security,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Run before controllers but after the firewall has resolved the user.
            KernelEvents::REQUEST => ['onKernelRequest', 8],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if ($request->getMethod() === 'OPTIONS') {
            return;
        }

        $path = $request->getPathInfo();
        if (!$this->isProtectedPath($path)) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        if ($user->isEmailVerified()) {
            return;
        }

        $event->setResponse(new JsonResponse([
            'error' => 'email_verification_required',
            'message' => 'Veuillez confirmer votre adresse email pour accéder à votre espace.',
            'email' => $user->getEmail(),
        ], 403));
    }

    private function isProtectedPath(string $path): bool
    {
        foreach (self::PROTECTED_PATH_PATTERNS as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }
}
