<?php

namespace App\Controller;

use App\Entity\Contest;
use App\Entity\ContestParticipation;
use App\Entity\ContestReward;
use App\Entity\ContestWinner;
use App\Entity\Merchant;
use App\Enum\ContestRewardType;
use App\Enum\ContestStatus;
use App\Repository\ContestParticipationRepository;
use App\Repository\ContestRepository;
use App\Repository\ContestWinnerRepository;
use App\Service\ContestDrawService;
use App\Service\ContestParticipationService;
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
        if (!$contest instanceof Contest || !$this->isContestOwnedByMerchant($contest, $merchant)) {
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
        if (!$contest instanceof Contest || !$this->isContestOwnedByMerchant($contest, $merchant)) {
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

    #[Route('/api/merchants/me/contests/{id}', name: 'merchant_contest_delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_MERCHANT');

        $merchant = $this->resolveActorMerchant();
        if (!$merchant instanceof Merchant) {
            return new JsonResponse(['error' => 'merchant_not_found'], 404);
        }

        $contest = $this->contestRepository->find($id);
        if (!$contest instanceof Contest || !$this->isContestOwnedByMerchant($contest, $merchant)) {
            return new JsonResponse(['error' => 'contest_not_found'], 404);
        }

        if (!$this->canDeleteContest($contest)) {
            return new JsonResponse(['error' => 'contest_delete_locked'], 409);
        }

        $this->entityManager->remove($contest);
        $this->entityManager->flush();

        return new JsonResponse(null, 204);
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
        if (!$source instanceof Contest || !$this->isContestOwnedByMerchant($source, $merchant)) {
            return new JsonResponse(['error' => 'contest_not_found'], 404);
        }

        if ($source->getStatus() !== ContestStatus::FINISHED) {
            return new JsonResponse(['error' => 'contest_clone_only_archived'], 409);
        }

        $clone = new Contest();
        $clone->setMerchant($merchant);
        $clone->setTitle($source->getTitle() . ' (copie)');
        $clone->setDescription($source->getDescription());
        $clone->setStatus(ContestStatus::SCHEDULED);

        $now = new \DateTimeImmutable();
        $clone->setStartAt($now->modify('+1 day')->setTime(0, 0, 0));
        $clone->setEndAt($now->modify('+8 day')->setTime(23, 59, 59));
        $clone->setDrawAt($now->modify('+8 day')->setTime(23, 59, 59));

        foreach ($source->getRewards() as $reward) {
            $newReward = new ContestReward();
            $newReward->setTitle($reward->getTitle());
            $newReward->setImageUrl($reward->getImageUrl());
            $newReward->setRank($reward->getRank());
            $newReward->setType($reward->getType());
            $newReward->setTargetValue($reward->getTargetValue());
            $newReward->setRewardDescription($reward->getRewardDescription());
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
        if (!$contest instanceof Contest || !$this->isContestOwnedByMerchant($contest, $merchant)) {
            return new JsonResponse(['error' => 'contest_not_found'], 404);
        }

        // Validate contest is in draw window
        $now = new \DateTimeImmutable();
        $drawAt = $contest->getDrawAt();
        if (!$drawAt instanceof \DateTimeImmutable || $now < $drawAt) {
            return new JsonResponse(['error' => 'contest_draw_not_ready'], 409);
        }

        try {
            $winner = $this->drawService->drawNextReward($contest);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }

        return new JsonResponse([
            'winner' => $winner instanceof ContestWinner ? $this->formatWinner($winner) : null,
            'remaining_rewards' => array_map(
                static fn (ContestReward $reward) => [
                    'id' => $reward->getId(),
                    'title' => $reward->getTitle(),
                    'image_url' => $reward->getImageUrl(),
                    'rank' => $reward->getRank(),
                    'type' => $reward->getType()->value,
                    'target_value' => $reward->getTargetValue(),
                    'reward_description' => $reward->getRewardDescription(),
                ],
                $this->drawService->getRemainingRewards($contest),
            ),
            'contest_status' => $contest->getStatus()->value,
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
        if (!$this->isContestOwnedByMerchant($winner->getContest(), $merchant)) {
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
                $contest->setStartAt($this->parseContestBoundaryDate($payload['start_at'] ?? null, false));
            } catch (\Throwable) {
                return new JsonResponse(['error' => 'contest_start_at_invalid'], 422);
            }
        }

        if (!$isUpdate || array_key_exists('end_at', $payload)) {
            try {
                $contest->setEndAt($this->parseContestBoundaryDate($payload['end_at'] ?? null, true));
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

        if (!$isUpdate || array_key_exists('status', $payload)) {
            $requestedStatus = $payload['status'] ?? null;
            if ($requestedStatus === null) {
                if (!$isUpdate) {
                    $contest->setStatus(ContestStatus::SCHEDULED);
                }
            } else {
                $normalized = is_string($requestedStatus) ? strtolower(trim($requestedStatus)) : '';
                $allowed = [
                    'draft' => ContestStatus::DRAFT,
                    'scheduled' => ContestStatus::SCHEDULED,
                    'published' => ContestStatus::SCHEDULED,
                ];
                if (!array_key_exists($normalized, $allowed)) {
                    return new JsonResponse(['error' => 'contest_status_invalid'], 422);
                }
                // On update, only allow toggling between DRAFT and SCHEDULED while the contest is still editable.
                if ($isUpdate && !in_array($contest->getStatus(), [ContestStatus::DRAFT, ContestStatus::SCHEDULED], true)) {
                    return new JsonResponse(['error' => 'contest_status_locked'], 409);
                }
                $contest->setStatus($allowed[$normalized]);
            }
        }

        $isDraft = $contest->getStatus() === ContestStatus::DRAFT;

        $startAt = $contest->getStartAt();
        $endAt = $contest->getEndAt();
        if (!$startAt instanceof \DateTimeImmutable || !$endAt instanceof \DateTimeImmutable) {
            return new JsonResponse(['error' => 'contest_dates_required'], 422);
        }
        if ($endAt < $startAt) {
            return new JsonResponse(['error' => 'contest_end_before_start'], 422);
        }
        // Start date must be in the future (matches the "from tomorrow" constraint enforced by the form).
        // Drafts can keep past start dates so partial work can be saved without losing edits.
        if (!$isDraft) {
            $today = (new \DateTimeImmutable('today'))->setTime(0, 0, 0);
            $startDay = $startAt->setTime(0, 0, 0);
            if ($startDay <= $today) {
                return new JsonResponse(['error' => 'contest_start_at_too_soon'], 422);
            }
        }

        $drawAt = $contest->getDrawAt();
        if ($drawAt instanceof \DateTimeImmutable && $drawAt < $endAt) {
            return new JsonResponse(['error' => 'contest_draw_before_end'], 422);
        }

        if (array_key_exists('rewards', $payload)) {
            if (!is_array($payload['rewards']) || (!$isDraft && count($payload['rewards']) === 0)) {
                return new JsonResponse(['error' => 'contest_rewards_required'], 422);
            }

            $validatedRewards = [];
            foreach ($payload['rewards'] as $rewardPayload) {
                if (!is_array($rewardPayload)) {
                    return new JsonResponse(['error' => 'contest_reward_invalid'], 422);
                }

                $label = trim((string) ($rewardPayload['title'] ?? ''));
                if ($label == '') {
                    return new JsonResponse(['error' => 'contest_reward_title_required'], 422);
                }

                $rewardType = ContestRewardType::TEXT;
                if (array_key_exists('type', $rewardPayload) && $rewardPayload['type'] !== null && $rewardPayload['type'] !== '') {
                    $rewardType = ContestRewardType::tryFrom(strtoupper(trim((string) $rewardPayload['type'])));
                    if ($rewardType === null) {
                        return new JsonResponse(['error' => 'contest_reward_type_invalid'], 422);
                    }
                }

                $targetValue = null;
                $rewardDescription = null;
                if ($rewardType !== ContestRewardType::TEXT) {
                    $rawTarget = $rewardPayload['target_value'] ?? null;
                    if ($rawTarget === null || $rawTarget === '') {
                        return new JsonResponse(['error' => 'contest_reward_target_required'], 422);
                    }
                    if (!is_numeric($rawTarget)) {
                        return new JsonResponse(['error' => 'contest_reward_target_invalid'], 422);
                    }
                    $targetValue = (int) $rawTarget;
                    if ($targetValue <= 0) {
                        return new JsonResponse(['error' => 'contest_reward_target_invalid'], 422);
                    }

                    $rawDescription = $rewardPayload['reward_description'] ?? null;
                    if ($rawDescription !== null) {
                        $rewardDescription = trim((string) $rawDescription);
                        if ($rewardDescription === '') {
                            $rewardDescription = null;
                        }
                    }
                }

                $validatedRewards[] = [
                    'title' => $label,
                    'image_url' => isset($rewardPayload['image_url']) ? trim((string) $rewardPayload['image_url']) : null,
                    'type' => $rewardType,
                    'target_value' => $targetValue,
                    'reward_description' => $rewardDescription,
                ];
            }

            // On update, flush reward deletions before inserts to avoid unique (contest_id, rank) collisions.
            if ($isUpdate && $contest->getRewards()->count() > 0) {
                foreach ($contest->getRewards()->toArray() as $existingReward) {
                    $contest->removeReward($existingReward);
                }
                $this->entityManager->flush();
            } elseif (!$isUpdate) {
                foreach ($contest->getRewards()->toArray() as $existingReward) {
                    $contest->removeReward($existingReward);
                }
            }

            $rank = 1;
            foreach ($validatedRewards as $validatedReward) {
                $label = $validatedReward['title'];

                $reward = new ContestReward();
                $reward->setTitle($label);
                $reward->setImageUrl($validatedReward['image_url']);
                $reward->setRank($rank);
                $reward->setType($validatedReward['type']);
                $reward->setTargetValue($validatedReward['target_value']);
                $reward->setRewardDescription($validatedReward['reward_description']);
                $contest->addReward($reward);
                $rank++;
            }
        } elseif (!$isDraft && (!$isUpdate || $contest->getRewards()->count() === 0)) {
            return new JsonResponse(['error' => 'contest_rewards_required'], 422);
        }

        return null;
    }

    private function parseContestBoundaryDate(mixed $rawValue, bool $isEndOfDay): \DateTimeImmutable
    {
        $raw = trim((string) $rawValue);
        if ($raw === '') {
            throw new \InvalidArgumentException('empty_date');
        }

        // Check if it's a date-only format (Y-m-d)
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
            if (!$date instanceof \DateTimeImmutable) {
                throw new \InvalidArgumentException('invalid_date');
            }

            return $isEndOfDay ? $date->setTime(23, 59, 59) : $date->setTime(0, 0, 0);
        }

        // Parse as datetime and preserve the time provided
        $dateTime = new \DateTimeImmutable($raw);

        return $dateTime;
    }

    private function canEditContest(Contest $contest): bool
    {
        $now = new \DateTimeImmutable();
        if ($contest->getStartAt() instanceof \DateTimeImmutable && $now >= $contest->getStartAt()) {
            return false;
        }

        return in_array($contest->getStatus(), [ContestStatus::DRAFT, ContestStatus::SCHEDULED], true);
    }

    private function canDeleteContest(Contest $contest): bool
    {
        $now = new \DateTimeImmutable();
        $startAt = $contest->getStartAt();

        return $startAt instanceof \DateTimeImmutable && $now < $startAt;
    }

    private function formatContest(Contest $contest): array
    {
        $participantSummaries = [];
        $participantCount = 0;
        $participationCount = 0;

        if (in_array($contest->getStatus(), [ContestStatus::ACTIVE, ContestStatus::FINISHED], true)) {
            $participantSummaries = array_map(
                static fn (array $participant): array => [
                    'customer_id' => $participant['customer_id'],
                    'customer_name' => $participant['customer_name'],
                    'customer_email' => $participant['customer_email'],
                    'participation_count' => $participant['participation_count'],
                ],
                $this->participationRepository->getParticipantSummaries($contest),
            );
            $participantCount = count($participantSummaries);
            $participationCount = array_reduce(
                $participantSummaries,
                static fn (int $carry, array $participant): int => $carry + (int) ($participant['participation_count'] ?? 0),
                0,
            );
        }

        $winners = [];
        if (in_array($contest->getStatus(), [ContestStatus::ACTIVE, ContestStatus::FINISHED], true)) {
            $winners = array_map(
                fn (ContestWinner $winner) => $this->formatWinner($winner),
                $this->winnerRepository->findByContest($contest),
            );
        }

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
                    'type' => $reward->getType()->value,
                    'target_value' => $reward->getTargetValue(),
                    'reward_description' => $reward->getRewardDescription(),
                ],
                $contest->getRewards()->toArray(),
            ),
            'participant_count' => $participantCount,
            'participation_count' => $participationCount,
            'participation_limit' => ContestParticipationService::MAX_PARTICIPATIONS_PER_CUSTOMER,
            'participants' => $participantSummaries,
            'winners' => $winners,
            'created_at' => $contest->getCreatedAt()?->format(DATE_ATOM),
            'updated_at' => $contest->getUpdatedAt()?->format(DATE_ATOM),
        ];
    }

    private function formatWinner(ContestWinner $winner): array
    {
        return [
            'id' => $winner->getId(),
            'customer_id' => $winner->getCustomer()?->getId(),
            'customer_name' => $winner->getCustomer()?->getName(),
            'customer_email' => $winner->getCustomer()?->getEmail(),
            'reward_id' => $winner->getReward()?->getId(),
            'reward_title' => $winner->getReward()?->getTitle(),
            'reward_rank' => $winner->getReward()?->getRank(),
            'is_claimed' => $winner->isClaimed(),
            'qr_token' => $winner->getQrCodeToken(),
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

    private function isContestOwnedByMerchant(Contest $contest, Merchant $merchant): bool
    {
        $contestMerchant = $contest->getMerchant();
        $contestMerchantId = $contestMerchant?->getId()?->toRfc4122();
        $merchantId = $merchant->getId()?->toRfc4122();

        return $contestMerchantId !== null && $merchantId !== null && $contestMerchantId === $merchantId;
    }
}
