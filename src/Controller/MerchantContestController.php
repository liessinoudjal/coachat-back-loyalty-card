<?php

namespace App\Controller;

use App\Entity\Contest;
use App\Entity\ContestParticipation;
use App\Entity\ContestReward;
use App\Entity\Merchant;
use App\Enum\ContestStatus;
use App\Repository\ContestParticipationRepository;
use App\Repository\ContestRepository;
use App\Repository\ContestWinnerRepository;
use App\Service\ContestDrawService;
use App\Service\SignupAlertMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class MerchantContestController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ContestRepository $contestRepository,
        private readonly ContestDrawService $drawService,
        private readonly ContestParticipationRepository $participationRepository,
        private readonly ContestWinnerRepository $winnerRepository,
        private readonly SignupAlertMailer $signupAlertMailer,
    ) {
    }

    #[Route('/api/merchants/me/contests', name: 'merchant_contest_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_MERCHANT');

        $merchant = $this->resolveActorMerchant();
        if (!$merchant instanceof Merchant) {
            return new JsonResponse(['error' => 'merchant_not_found'], 404);
        }

        $contests = $this->contestRepository->findByMerchantOrdered($merchant);

        return new JsonResponse([
            'member' => array_map(fn (Contest $contest) => $this->formatContest($contest), $contests),
        ]);
    }

    #[Route('/api/merchants/me/contests', name: 'merchant_contest_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_MERCHANT');

        $merchant = $this->resolveActorMerchant();
        if (!$merchant instanceof Merchant) {
            return new JsonResponse(['error' => 'merchant_not_found'], 404);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'contest_payload_invalid'], 400);
        }

        $contest = new Contest();
        $contest->setMerchant($merchant);

        $error = $this->applyContestPayload($contest, $payload, false);
        if ($error instanceof JsonResponse) {
            return $error;
        }

        $this->entityManager->persist($contest);
        $this->entityManager->flush();

        $this->signupAlertMailer->notifyContestCreated($contest);

        return new JsonResponse($this->formatContest($contest), 201);
    }

    #[Route('/api/merchants/me/contests/{id}', name: 'merchant_contest_show', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_MERCHANT');

        $merchant = $this->resolveActorMerchant();
        if (!$merchant instanceof Merchant) {
            return new JsonResponse(['error' => 'merchant_not_found'], 404);
        }

        $contest = $this->contestRepository->find($id);
        if (!$contest instanceof Contest || $contest->getMerchant() !== $merchant) {
            return new JsonResponse(['error' => 'contest_not_found'], 404);
        }

        return new JsonResponse($this->formatContest($contest));
    }

    #[Route('/api/merchants/me/contests/{id}', name: 'merchant_contest_update', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_MERCHANT');

        $merchant = $this->resolveActorMerchant();
        if (!$merchant instanceof Merchant) {
            return new JsonResponse(['error' => 'merchant_not_found'], 404);
        }

        $contest = $this->contestRepository->find($id);
        if (!$contest instanceof Contest || $contest->getMerchant() !== $merchant) {
            return new JsonResponse(['error' => 'contest_not_found'], 404);
        }

        if (!$this->canEditContest($contest)) {
            return new JsonResponse(['error' => 'contest_edit_locked'], 409);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'contest_payload_invalid'], 400);
        }

        $error = $this->applyContestPayload($contest, $payload, true);
        if ($error instanceof JsonResponse) {
            return $error;
        }

        $this->entityManager->flush();

        return new JsonResponse($this->formatContest($contest));
    }

    #[Route('/api/merchants/me/contests/{id}/clone', name: 'merchant_contest_clone', methods: ['POST'])]
    public function cloneContest(string $id): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_MERCHANT');

        $merchant = $this->resolveActorMerchant();
        if (!$merchant instanceof Merchant) {
            return new JsonResponse(['error' => 'merchant_not_found'], 404);
        }

        $source = $this->contestRepository->find($id);
        if (!$source instanceof Contest || $source->getMerchant() !== $merchant) {
            return new JsonResponse(['error' => 'contest_not_found'], 404);
        }

        $clone = new Contest();
        $clone->setMerchant($merchant);
        $clone->setTitle($source->getTitle() . ' (copie)');
        $clone->setDescription($source->getDescription());
        $clone->setStatus(ContestStatus::DRAFT);

        $now = new \DateTimeImmutable();
        $clone->setStartAt($now->modify('+1 day'));
        $clone->setEndAt($now->modify('+8 day'));
        $clone->setDrawAt($now->modify('+8 day'));

        foreach ($source->getRewards() as $reward) {
            $newReward = new ContestReward();
            $newReward->setTitle($reward->getTitle());
            $newReward->setImageUrl($reward->getImageUrl());
            $newReward->setRank($reward->getRank());
            $clone->addReward($newReward);
        }

        $this->entityManager->persist($clone);
        $this->entityManager->flush();

        return new JsonResponse($this->formatContest($clone), 201);
    }

    #[Route('/api/merchants/me/contests/{id}/draw', name: 'merchant_contest_draw', methods: ['POST'])]
    public function draw(string $id): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_MERCHANT');

        $merchant = $this->resolveActorMerchant();
        if (!$merchant instanceof Merchant) {
            return new JsonResponse(['error' => 'merchant_not_found'], 404);
        }

        $contest = $this->contestRepository->find($id);
        if (!$contest instanceof Contest || $contest->getMerchant() !== $merchant) {
            return new JsonResponse(['error' => 'contest_not_found'], 404);
        }

        // Validate contest is in draw window
        $now = new \DateTimeImmutable();
        $drawAt = $contest->getDrawAt();
        if (!$drawAt instanceof \DateTimeImmutable || $now < $drawAt) {
            return new JsonResponse(['error' => 'contest_draw_not_ready'], 409);
        }

        // Execute draw
        try {
            $winners = $this->drawService->executeDraw($contest);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }

        return new JsonResponse([
            'winners' => array_map(
                fn ($winner) => [
                    'id' => $winner->getId(),
                    'customer_id' => $winner->getCustomer()->getId(),
                    'customer_name' => $winner->getCustomer()->getName(),
                    'reward_id' => $winner->getReward()->getId(),
                    'reward_title' => $winner->getReward()->getTitle(),
                    'reward_rank' => $winner->getReward()->getRank(),
                    'qr_token' => $winner->getQrCodeToken(),
                ],
                $winners,
            ),
        ]);
    }

    #[Route('/api/contest-winners/claim-by-qr', name: 'claim_contest_reward_by_qr', methods: ['POST'])]
    public function claimRewardByQr(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_MERCHANT');

        $merchant = $this->resolveActorMerchant();
        if (!$merchant instanceof Merchant) {
            return new JsonResponse(['error' => 'merchant_not_found'], 404);
        }

        $payload = json_decode($request->getContent(), true);
        $qrToken = trim((string) ($payload['qr_token'] ?? ''));
        if ($qrToken === '') {
            return new JsonResponse(['error' => 'qr_token_required'], 422);
        }

        $winner = $this->winnerRepository->findByQrToken($qrToken);
        if (!$winner) {
            return new JsonResponse(['error' => 'winner_not_found'], 404);
        }

        // Validate merchant owns the contest
        if ($winner->getContest()->getMerchant() !== $merchant) {
            return new JsonResponse(['error' => 'forbidden'], 403);
        }

        // Validate not already claimed
        if ($winner->isClaimed()) {
            return new JsonResponse(['error' => 'reward_already_claimed'], 409);
        }

        // Mark as claimed
        $winner->markAsClaimed();
        $this->entityManager->flush();

        return new JsonResponse([
            'id' => $winner->getId(),
            'customer_name' => $winner->getCustomer()->getName(),
            'reward_title' => $winner->getReward()->getTitle(),
            'is_claimed' => $winner->isClaimed(),
            'claimed_at' => $winner->getClaimedAt()?->format(DATE_ATOM),
        ]);
    }

    private function applyContestPayload(Contest $contest, array $payload, bool $isUpdate): ?JsonResponse
    {
        if (!$isUpdate || array_key_exists('title', $payload)) {
            $title = trim((string) ($payload['title'] ?? ''));
            if ($title == '') {
                return new JsonResponse(['error' => 'contest_title_required'], 422);
            }
            $contest->setTitle($title);
        }

        if (array_key_exists('description', $payload) || !$isUpdate) {
            $description = $payload['description'] ?? null;
            $contest->setDescription($description !== null ? trim((string) $description) : null);
        }

        if (!$isUpdate || array_key_exists('start_at', $payload)) {
            try {
                $contest->setStartAt(new \DateTimeImmutable((string) ($payload['start_at'] ?? '')));
            } catch (\Throwable) {
                return new JsonResponse(['error' => 'contest_start_at_invalid'], 422);
            }
        }

        if (!$isUpdate || array_key_exists('end_at', $payload)) {
            try {
                $contest->setEndAt(new \DateTimeImmutable((string) ($payload['end_at'] ?? '')));
            } catch (\Throwable) {
                return new JsonResponse(['error' => 'contest_end_at_invalid'], 422);
            }
        }

        if (array_key_exists('draw_at', $payload)) {
            try {
                $drawAtRaw = $payload['draw_at'];
                $contest->setDrawAt($drawAtRaw ? new \DateTimeImmutable((string) $drawAtRaw) : null);
            } catch (\Throwable) {
                return new JsonResponse(['error' => 'contest_draw_at_invalid'], 422);
            }
        } elseif (!$isUpdate && $contest->getEndAt() instanceof \DateTimeImmutable) {
            $contest->setDrawAt($contest->getEndAt());
        }

        if (array_key_exists('status', $payload)) {
            try {
                $contest->setStatus(ContestStatus::from((string) $payload['status']));
            } catch (\Throwable) {
                return new JsonResponse(['error' => 'contest_status_invalid'], 422);
            }
        } elseif (!$isUpdate) {
            $contest->setStatus(ContestStatus::DRAFT);
        }

        $startAt = $contest->getStartAt();
        $endAt = $contest->getEndAt();
        if (!$startAt instanceof \DateTimeImmutable || !$endAt instanceof \DateTimeImmutable) {
            return new JsonResponse(['error' => 'contest_dates_required'], 422);
        }
        if ($endAt <= $startAt) {
            return new JsonResponse(['error' => 'contest_end_before_start'], 422);
        }

        if (array_key_exists('rewards', $payload)) {
            if (!is_array($payload['rewards']) || count($payload['rewards']) === 0) {
                return new JsonResponse(['error' => 'contest_rewards_required'], 422);
            }

            foreach ($contest->getRewards()->toArray() as $existingReward) {
                $contest->removeReward($existingReward);
            }

            $rank = 1;
            foreach ($payload['rewards'] as $rewardPayload) {
                if (!is_array($rewardPayload)) {
                    return new JsonResponse(['error' => 'contest_reward_invalid'], 422);
                }

                $label = trim((string) ($rewardPayload['title'] ?? ''));
                if ($label == '') {
                    return new JsonResponse(['error' => 'contest_reward_title_required'], 422);
                }

                $reward = new ContestReward();
                $reward->setTitle($label);
                $reward->setImageUrl(isset($rewardPayload['image_url']) ? trim((string) $rewardPayload['image_url']) : null);
                $reward->setRank($rank);
                $contest->addReward($reward);
                $rank++;
            }
        } elseif (!$isUpdate || $contest->getRewards()->count() === 0) {
            return new JsonResponse(['error' => 'contest_rewards_required'], 422);
        }

        return null;
    }

    private function canEditContest(Contest $contest): bool
    {
        $now = new \DateTimeImmutable();
        if ($contest->getStartAt() instanceof \DateTimeImmutable && $now >= $contest->getStartAt()) {
            return false;
        }

        return in_array($contest->getStatus(), [ContestStatus::DRAFT, ContestStatus::SCHEDULED], true);
    }

    private function formatContest(Contest $contest): array
    {
        return [
            'id' => $contest->getId()?->toRfc4122(),
            'merchant_id' => $contest->getMerchant()?->getId()?->toRfc4122(),
            'title' => $contest->getTitle(),
            'description' => $contest->getDescription(),
            'start_at' => $contest->getStartAt()?->format(DATE_ATOM),
            'end_at' => $contest->getEndAt()?->format(DATE_ATOM),
            'draw_at' => $contest->getDrawAt()?->format(DATE_ATOM),
            'status' => $contest->getStatus()->value,
            'rewards' => array_map(
                static fn (ContestReward $reward) => [
                    'id' => $reward->getId(),
                    'title' => $reward->getTitle(),
                    'image_url' => $reward->getImageUrl(),
                    'rank' => $reward->getRank(),
                ],
                $contest->getRewards()->toArray(),
            ),
            'created_at' => $contest->getCreatedAt()?->format(DATE_ATOM),
            'updated_at' => $contest->getUpdatedAt()?->format(DATE_ATOM),
        ];
    }

    private function resolveActorMerchant(): ?Merchant
    {
        $user = $this->getUser();
        if ($user === null) {
            return null;
        }

        $merchant = $user->getMerchant();
        if ($merchant instanceof Merchant) {
            return $merchant;
        }

        $roles = $user->getRoles();
        if (!in_array('ROLE_EQUIPIER', $roles, true) && !in_array('ROLE_MERCHANT', $roles, true)) {
            return null;
        }

        return $user->getCustomer()?->getStaffMerchant();
    }
}
