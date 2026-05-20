<?php

namespace App\Controller;

use App\Entity\Contest;
use App\Entity\Customer;
use App\Entity\Merchant;
use App\Enum\ContestStatus;
use App\Repository\ContestParticipationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

final class CustomerContestController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ContestParticipationRepository $participationRepository,
    ) {
    }

    #[Route('/api/customer/contests', name: 'customer_contests_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'unauthorized'], 401);
        }

        $customer = $user->getCustomer();
        if (!$customer instanceof Customer) {
            return new JsonResponse(['error' => 'customer_not_found'], 404);
        }

        // Collect all linked merchant IDs (ManyToMany + legacy single relation)
        $merchants = [];
        $seenIds = [];

        foreach ($customer->getMerchants() as $m) {
            $id = $m->getId()?->toRfc4122();
            if ($id !== null && !isset($seenIds[$id])) {
                $seenIds[$id] = true;
                $merchants[] = $m;
            }
        }

        $primaryMerchant = $customer->getMerchant();
        if ($primaryMerchant instanceof Merchant) {
            $id = $primaryMerchant->getId()?->toRfc4122();
            if ($id !== null && !isset($seenIds[$id])) {
                $merchants[] = $primaryMerchant;
            }
        }

        if ($merchants === []) {
            return new JsonResponse(['contests' => [], 'active_contests_count' => 0]);
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));
        $statuses = [ContestStatus::SCHEDULED, ContestStatus::ACTIVE];

        $contests = [];
        $activeCount = 0;

        foreach ($merchants as $merchant) {
            $merchantContests = $this->entityManager->getRepository(Contest::class)
                ->createQueryBuilder('c')
                ->where('c.merchant = :merchantId')
                ->andWhere('c.status IN (:statuses)')
                ->andWhere('c.endAt >= :now')
                ->setParameter('merchantId', $merchant->getId(), 'uuid')
                ->setParameter('statuses', $statuses)
                ->setParameter('now', $now, 'datetime_immutable')
                ->orderBy('c.startAt', 'ASC')
                ->getQuery()
                ->getResult();

            foreach ($merchantContests as $contest) {
                if (!$contest instanceof Contest) {
                    continue;
                }

                $contestId = $contest->getId()?->toRfc4122();
                if ($contestId === null) {
                    continue;
                }

                // Participation data for this customer
                $participationCount = $this->participationRepository->countByContestAndCustomer($contest, $customer);
                $isParticipating = $participationCount > 0;

                // Check if the customer has won
                $winningParticipation = null;
                if ($isParticipating) {
                    $winningParticipation = $this->participationRepository->findOneBy([
                        'contest' => $contest,
                        'customer' => $customer,
                        'isWinningEntry' => true,
                    ]);
                }

                // Rewards
                $rewards = [];
                foreach ($contest->getRewards() as $reward) {
                    $rewards[] = [
                        'id' => $reward->getId(),
                        'title' => $reward->getTitle(),
                        'rank' => $reward->getRank(),
                    ];
                }

                $isActive = $contest->getStatus() === ContestStatus::ACTIVE;
                if ($isActive || ($contest->getStatus() === ContestStatus::SCHEDULED && $contest->getStartAt() <= $now)) {
                    ++$activeCount;
                }

                $contests[] = [
                    'id' => $contestId,
                    'title' => $contest->getTitle(),
                    'description' => $contest->getDescription(),
                    'start_at' => $contest->getStartAt()?->format(DATE_ATOM) ?? '',
                    'end_at' => $contest->getEndAt()?->format(DATE_ATOM) ?? '',
                    'draw_at' => $contest->getDrawAt()?->format(DATE_ATOM),
                    'status' => $contest->getStatus()->value,
                    'merchant' => [
                        'id' => $merchant->getId()?->toRfc4122(),
                        'company_name' => $merchant->getCompanyName(),
                        'logo_url' => $merchant->getLogoUrl(),
                    ],
                    'rewards' => $rewards,
                    'is_participating' => $isParticipating,
                    'participation_count' => $participationCount,
                    'has_won' => $winningParticipation !== null,
                ];
            }
        }

        return new JsonResponse([
            'contests' => $contests,
            'active_contests_count' => $activeCount,
        ]);
    }
}
