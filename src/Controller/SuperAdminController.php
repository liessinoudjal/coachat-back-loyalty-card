<?php

namespace App\Controller;

use App\Entity\LoyaltyProgram;
use App\Entity\Merchant;
use App\Entity\MerchantGoogleReviewModule;
use App\Entity\User;
use App\Repository\LoyaltyProgramRepository;
use App\Repository\MerchantRepository;
use App\Service\GoogleReviewModuleManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class SuperAdminController extends AbstractController
{
    public function __construct(
        private readonly MerchantRepository $merchantRepository,
        private readonly LoyaltyProgramRepository $loyaltyProgramRepository,
        private readonly GoogleReviewModuleManager $googleReviewModuleManager,
    ) {
    }

    #[Route('/api/super-admin/me', name: 'super_admin_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        return new JsonResponse([
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'name' => $user->getName(),
            'google_id' => $user->getGoogleId(),
            'roles' => $user->getRoles(),
            'is_super_admin' => in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true),
            'merchant' => $user->getMerchant() instanceof Merchant ? $this->formatMerchant($user->getMerchant()) : null,
        ]);
    }

    #[Route('/api/super-admin/merchants', name: 'super_admin_merchants_list', methods: ['GET'])]
    public function merchants(): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $merchants = $this->merchantRepository->findBy([], ['companyName' => 'ASC']);

        return new JsonResponse([
            'items' => array_map(fn (Merchant $merchant): array => $this->formatMerchant($merchant), $merchants),
            'total' => count($merchants),
        ]);
    }

    #[Route('/api/super-admin/merchants/{merchantId}/loyalty-programs', name: 'super_admin_merchant_loyalty_programs', methods: ['GET'])]
    public function merchantLoyaltyPrograms(string $merchantId): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $merchant = $this->merchantRepository->find($merchantId);
        if (!$merchant instanceof Merchant) {
            return new JsonResponse(['error' => 'merchant_not_found'], 404);
        }

        $programs = $this->loyaltyProgramRepository->findBy(['merchant' => $merchant], ['name' => 'ASC']);

        return new JsonResponse([
            'merchant' => $this->formatMerchantPresenter($merchant),
            'items' => array_map(fn (LoyaltyProgram $program): array => $this->formatLoyaltyProgram($program), $programs),
            'total' => count($programs),
        ]);
    }

    #[Route('/api/super-admin/merchants/{merchantId}/google-review-module', name: 'super_admin_merchant_google_review_module', methods: ['GET'])]
    public function merchantGoogleReviewModule(string $merchantId): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $merchant = $this->merchantRepository->find($merchantId);
        if (!$merchant instanceof Merchant) {
            return new JsonResponse(['error' => 'merchant_not_found'], 404);
        }

        $module = $this->googleReviewModuleManager->getForMerchant($merchant);
        if (!$module instanceof MerchantGoogleReviewModule) {
            return new JsonResponse(['error' => 'google_review_module_not_found'], 404);
        }

        return new JsonResponse($this->formatGoogleReviewModule($module));
    }

    private function formatMerchant(Merchant $merchant): array
    {
        $user = $merchant->getUser();
        $plan = $merchant->getPlan();

        return [
            'id' => $merchant->getId()?->toRfc4122(),
            'company_name' => $merchant->getCompanyName(),
            'email' => $merchant->getEmail(),
            'phone' => $merchant->getPhone(),
            'address' => $merchant->getAddress(),
            'postal_code' => $merchant->getPostalCode(),
            'city' => $merchant->getCity(),
            'logo_url' => $merchant->getLogoUrl(),
            'stripe_customer_id' => $merchant->getStripeCustomerId(),
            'trial_ends_at' => $merchant->getTrialEndsAt()?->format('Y-m-d\TH:i:s\Z'),
            'current_period_start_at' => $merchant->getCurrentPeriodStartAt()?->format('Y-m-d\TH:i:s\Z'),
            'current_period_end_at' => $merchant->getCurrentPeriodEndAt()?->format('Y-m-d\TH:i:s\Z'),
            'accepted_terms' => $merchant->isAcceptedTerms(),
            'accepted_terms_version' => $merchant->getAcceptedTermsVersion(),
            'accepted_terms_accepted_at' => $merchant->getAcceptedTermsAcceptedAt() ? (clone $merchant->getAcceptedTermsAcceptedAt())->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z') : null,
            'subscription_status' => $merchant->getSubscriptionStatus(),
            'active_loyalty_program_count' => $merchant->getActiveLoyaltyProgramCount(),
            'loyalty_program_count' => $merchant->getLoyaltyPrograms()->count(),
            'loyalty_card_count' => $merchant->getLoyaltyCards()->count(),
            'transaction_count' => $merchant->getTransactions()->count(),
            'reward_count' => $merchant->getRewards()->count(),
            'plan' => $plan ? [
                'id' => $plan->getId(),
                'slug' => $plan->getSlug(),
                'name' => $plan->getName(),
                'price_monthly' => $plan->getPriceMonthly(),
                'max_customers' => $plan->getMaxCustomers(),
                'max_programs' => $plan->getMaxPrograms(),
                'has_wallet_integration' => $plan->isHasWalletIntegration(),
                'has_push_notifications' => $plan->isHasPushNotifications(),
                'has_advanced_stats' => $plan->isHasAdvancedStats(),
            ] : null,
            'user' => $user ? [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'name' => $user->getName(),
                'roles' => $user->getRoles(),
            ] : null,
        ];
    }

    private function formatMerchantPresenter(Merchant $merchant): array
    {
        return [
            'id' => $merchant->getId()?->toRfc4122(),
            'company_name' => $merchant->getCompanyName(),
            'logo_url' => $merchant->getLogoUrl(),
            'city' => $merchant->getCity(),
            'subscription_status' => $merchant->getSubscriptionStatus(),
            'merchant_ref' => $merchant->getId()?->toRfc4122(),
            'customer_signup_qr_value' => $merchant->getId()?->toRfc4122(),
        ];
    }

    private function formatLoyaltyProgram(LoyaltyProgram $program): array
    {
        return [
            'id' => $program->getId(),
            'name' => $program->getName(),
            'description' => $program->getDescription(),
            'type' => $program->getType()->value,
            'points_per_euro' => $program->getPointsPerEuro(),
            'points_target' => $program->getPointsTarget(),
            'stamp_target' => $program->getStampTarget(),
            'reward_description' => $program->getRewardDescription(),
            'is_active' => $program->isActive(),
            'loyalty_card_count' => $program->getLoyaltyCards()->count(),
            'reward_count' => $program->getRewards()->count(),
        ];
    }

    private function formatGoogleReviewModule(MerchantGoogleReviewModule $module): array
    {
        $merchant = $module->getMerchant();

        return [
            'id' => $module->getId()?->toRfc4122(),
            'merchant_id' => $merchant?->getId()?->toRfc4122(),
            'merchant_name' => $merchant?->getCompanyName(),
            'merchant_logo_url' => $merchant?->getLogoUrl(),
            'is_enabled' => $module->isEnabled(),
            'display_name' => $module->getDisplayName(),
            'google_review_url' => $module->getGoogleReviewUrl(),
            'show_in_customer_dashboard' => $module->isShowInCustomerDashboard(),
            'show_qr_code' => $module->isShowQrCode(),
            'reward_options' => $module->getRewardOptions(),
            'is_configuration_complete' => $this->googleReviewModuleManager->isComplete($module),
            'created_at' => $module->getCreatedAt()?->format(DATE_ATOM),
            'updated_at' => $module->getUpdatedAt()?->format(DATE_ATOM),
        ];
    }
}
