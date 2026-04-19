<?php

namespace App\Service;

use App\Entity\Merchant;
use App\Entity\MerchantGoogleReviewModule;
use App\Exception\GoogleReviewException;
use App\Repository\MerchantGoogleReviewModuleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class GoogleReviewModuleManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MerchantGoogleReviewModuleRepository $moduleRepository,
        private readonly ValidatorInterface $validator,
        private readonly GoogleReviewUrlValidator $urlValidator,
    ) {
    }

    public function getOrCreateForMerchant(Merchant $merchant): MerchantGoogleReviewModule
    {
        $module = $this->moduleRepository->findOneByMerchant($merchant);
        if ($module instanceof MerchantGoogleReviewModule) {
            return $module;
        }

        $module = new MerchantGoogleReviewModule();
        $module->setMerchant($merchant);

        $this->entityManager->persist($module);

        return $module;
    }

    public function getForMerchant(Merchant $merchant): ?MerchantGoogleReviewModule
    {
        return $this->moduleRepository->findOneByMerchant($merchant);
    }

    public function applyPayload(MerchantGoogleReviewModule $module, array $payload): MerchantGoogleReviewModule
    {
        if (array_key_exists('is_enabled', $payload)) {
            if (!is_bool($payload['is_enabled'])) {
                throw new GoogleReviewException('google_review_payload_invalid', 400);
            }

            $module->setIsEnabled($payload['is_enabled']);
        }

        if (array_key_exists('display_name', $payload)) {
            if (!is_string($payload['display_name']) || trim($payload['display_name']) === '') {
                throw new GoogleReviewException('google_review_payload_invalid', 400);
            }

            $module->setDisplayName(trim($payload['display_name']));
        }

        if (array_key_exists('google_review_url', $payload)) {
            if ($payload['google_review_url'] !== null && !is_string($payload['google_review_url'])) {
                throw new GoogleReviewException('google_review_url_invalid', 422);
            }

            $module->setGoogleReviewUrl($this->urlValidator->normalize($payload['google_review_url']));
        }

        if (array_key_exists('show_in_customer_dashboard', $payload)) {
            if (!is_bool($payload['show_in_customer_dashboard'])) {
                throw new GoogleReviewException('google_review_payload_invalid', 400);
            }

            $module->setShowInCustomerDashboard($payload['show_in_customer_dashboard']);
        }

        if (array_key_exists('show_qr_code', $payload)) {
            if (!is_bool($payload['show_qr_code'])) {
                throw new GoogleReviewException('google_review_payload_invalid', 400);
            }

            $module->setShowQrCode($payload['show_qr_code']);
        }

        if (array_key_exists('reward_options', $payload)) {
            $module->setRewardOptions($this->normalizeRewardOptions($payload['reward_options']));
        }

        $violations = $this->validator->validate($module);
        if (count($violations) > 0) {
            $violation = $violations[0];
            $message = (string) $violation->getMessage();

            throw new GoogleReviewException($message, 422);
        }

        return $module;
    }

    public function isComplete(MerchantGoogleReviewModule $module): bool
    {
        return $module->isEnabled()
            && $module->getGoogleReviewUrl() !== null
            && GoogleReviewUrlValidator::isAllowedGoogleReviewUrl($module->getGoogleReviewUrl())
            && $module->getActiveRewardOptions() !== [];
    }

    public function isVisibleToCustomer(MerchantGoogleReviewModule $module): bool
    {
        return $module->isShowInCustomerDashboard() && $this->isComplete($module);
    }

    /**
     * @return list<array{id: string, label: string, description: ?string, active: bool, order: int}>
     */
    public function normalizeRewardOptions(mixed $rewardOptions): array
    {
        if (!is_array($rewardOptions)) {
            throw new GoogleReviewException('google_review_rewards_invalid', 422);
        }

        $normalized = [];

        foreach ($rewardOptions as $index => $option) {
            if (!is_array($option)) {
                throw new GoogleReviewException('google_review_rewards_invalid', 422);
            }

            $label = $option['label'] ?? null;
            $description = $option['description'] ?? null;
            $active = $option['active'] ?? null;
            $order = $option['order'] ?? $index + 1;
            $id = $option['id'] ?? Uuid::v4()->toRfc4122();

            if (!is_string($label) || trim($label) === '') {
                throw new GoogleReviewException('google_review_rewards_invalid', 422);
            }

            if ($description !== null && !is_string($description)) {
                throw new GoogleReviewException('google_review_rewards_invalid', 422);
            }

            if (!is_bool($active)) {
                throw new GoogleReviewException('google_review_rewards_invalid', 422);
            }

            if (!is_int($order) && !ctype_digit((string) $order)) {
                throw new GoogleReviewException('google_review_rewards_invalid', 422);
            }

            if (!is_string($id) || trim($id) === '') {
                throw new GoogleReviewException('google_review_rewards_invalid', 422);
            }

            $normalized[] = [
                'id' => trim($id),
                'label' => trim($label),
                'description' => $this->normalizeNullableString($description),
                'active' => $active,
                'order' => (int) $order,
            ];
        }

        usort($normalized, static function (array $left, array $right): int {
            $orderComparison = $left['order'] <=> $right['order'];

            return $orderComparison !== 0 ? $orderComparison : strcmp($left['label'], $right['label']);
        });

        return $normalized;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}