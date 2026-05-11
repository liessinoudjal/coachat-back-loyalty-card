<?php

namespace App\Service;

use App\Entity\Contest;
use App\Entity\Customer;
use App\Entity\CustomerMerchantNotificationPreference;
use App\Repository\ContestParticipationRepository;
use App\Repository\ContestRepository;
use App\Repository\CustomerMerchantNotificationPreferenceRepository;
use App\Repository\CustomerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class ContestNotificationDispatcher
{
    public function __construct(
        private readonly ContestRepository $contestRepository,
        private readonly CustomerRepository $customerRepository,
        private readonly CustomerMerchantNotificationPreferenceRepository $preferenceRepository,
        private readonly ContestParticipationRepository $participationRepository,
        private readonly NotificationService $notificationService,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{day_before_notifications_sent_for_contests: int, start_notifications_sent_for_contests: int, ending_soon_notifications_sent_for_contests: int}
     */
    public function dispatch(\DateTimeImmutable $today): array
    {
        $dayStart = $today->setTime(0, 0, 0);

        $tomorrowStart = $dayStart->modify('+1 day');
        $tomorrowEnd = $tomorrowStart->modify('+1 day');
        $dayBeforeContests = $this->contestRepository->findDayBeforeStartBetween($tomorrowStart, $tomorrowEnd);

        $startContests = $this->contestRepository->findStartingBetween($dayStart, $dayStart->modify('+1 day'));

        $endingSoonStart = $dayStart->modify('+2 days');
        $endingSoonEnd = $endingSoonStart->modify('+1 day');
        $endingSoonContests = $this->contestRepository->findEndingBetween($endingSoonStart, $endingSoonEnd);

        $this->logger->info('contest.dispatch.started', [
            'today' => $today->format('Y-m-d'),
            'day_before_count' => count($dayBeforeContests),
            'start_count' => count($startContests),
            'ending_soon_count' => count($endingSoonContests),
        ]);

        $dayBeforeMarked = 0;
        foreach ($dayBeforeContests as $contest) {
            $ok = $this->notifyForDayBeforeStart($contest);
            if ($ok) {
                $contest->setDayBeforeNotificationSentAt(new \DateTimeImmutable());
                $dayBeforeMarked++;
            }
        }

        $startMarked = 0;
        foreach ($startContests as $contest) {
            $ok = $this->notifyForStart($contest);
            if ($ok) {
                $contest->setStartNotificationSentAt(new \DateTimeImmutable());
                $startMarked++;
            }
        }

        $endingMarked = 0;
        foreach ($endingSoonContests as $contest) {
            $ok = $this->notifyForEndingSoon($contest);
            if ($ok) {
                $contest->setEndingSoonNotificationSentAt(new \DateTimeImmutable());
                $endingMarked++;
            }
        }

        $this->entityManager->flush();

        $this->logger->info('contest.dispatch.completed', [
            'today' => $today->format('Y-m-d'),
            'day_before_notifications_sent_for_contests' => $dayBeforeMarked,
            'start_notifications_sent_for_contests' => $startMarked,
            'ending_soon_notifications_sent_for_contests' => $endingMarked,
        ]);

        return [
            'day_before_notifications_sent_for_contests' => $dayBeforeMarked,
            'start_notifications_sent_for_contests' => $startMarked,
            'ending_soon_notifications_sent_for_contests' => $endingMarked,
        ];
    }

    /**
     * Preview recipients and contest counts for a given day without sending emails.
     *
     * @return array{
     *   day_before_notifications_would_be_sent_for_contests: int,
     *   start_notifications_would_be_sent_for_contests: int,
     *   ending_soon_notifications_would_be_sent_for_contests: int,
     *   day_before_targeted_contests: int,
     *   start_targeted_contests: int,
     *   ending_soon_targeted_contests: int
     * }
     */
    public function preview(\DateTimeImmutable $today): array
    {
        $dayStart = $today->setTime(0, 0, 0);

        $tomorrowStart = $dayStart->modify('+1 day');
        $tomorrowEnd = $tomorrowStart->modify('+1 day');
        $dayBeforeContests = $this->contestRepository->findDayBeforeStartBetween($tomorrowStart, $tomorrowEnd);

        $startContests = $this->contestRepository->findStartingBetween($dayStart, $dayStart->modify('+1 day'));

        $endingSoonStart = $dayStart->modify('+2 days');
        $endingSoonEnd = $endingSoonStart->modify('+1 day');
        $endingSoonContests = $this->contestRepository->findEndingBetween($endingSoonStart, $endingSoonEnd);

        $dayBeforeWouldSend = 0;
        foreach ($dayBeforeContests as $contest) {
            $dayBeforeWouldSend += $this->countContestRecipients($contest);
        }

        $startWouldSend = 0;
        foreach ($startContests as $contest) {
            $startWouldSend += $this->countContestRecipients($contest);
        }

        $endingSoonWouldSend = 0;
        foreach ($endingSoonContests as $contest) {
            $endingSoonWouldSend += $this->countContestRecipients($contest);
        }

        $result = [
            'day_before_notifications_would_be_sent_for_contests' => $dayBeforeWouldSend,
            'start_notifications_would_be_sent_for_contests' => $startWouldSend,
            'ending_soon_notifications_would_be_sent_for_contests' => $endingSoonWouldSend,
            'day_before_targeted_contests' => count($dayBeforeContests),
            'start_targeted_contests' => count($startContests),
            'ending_soon_targeted_contests' => count($endingSoonContests),
        ];

        $this->logger->info('contest.dispatch.preview', [
            'today' => $today->format('Y-m-d'),
            ...$result,
        ]);

        return $result;
    }

    private function notifyForDayBeforeStart(Contest $contest): bool
    {
        $merchant = $contest->getMerchant();
        if ($merchant === null) {
            return false;
        }

        return $this->notifyContestCustomers(
            $contest,
            fn (Customer $customer): bool => $this->notificationService->notifyContestDayBeforeStart($customer, $merchant, $contest),
        );
    }

    private function notifyForStart(Contest $contest): bool
    {
        $merchant = $contest->getMerchant();
        if ($merchant === null) {
            return false;
        }

        return $this->notifyContestCustomers(
            $contest,
            fn (Customer $customer): bool => $this->notificationService->notifyContestStarts($customer, $merchant, $contest),
        );
    }

    private function notifyForEndingSoon(Contest $contest): bool
    {
        $merchant = $contest->getMerchant();
        if ($merchant === null) {
            return false;
        }

        return $this->notifyContestCustomers(
            $contest,
            fn (Customer $customer): bool => $this->notificationService->notifyContestEndingSoon($customer, $merchant, $contest),
        );
    }

    /**
     * @param callable(Customer): bool $sendCallback
     */
    private function notifyContestCustomers(Contest $contest, callable $sendCallback): bool
    {
        $merchant = $contest->getMerchant();
        if ($merchant === null) {
            return false;
        }

        $participantIds = array_flip($this->participationRepository->findParticipantCustomerIds($contest));
        $customers = $this->customerRepository->findByMerchant($merchant);

        $hasFailure = false;
        foreach ($customers as $customer) {
            $customerId = $customer->getId();
            if ($customerId !== null && isset($participantIds[$customerId])) {
                continue;
            }

            if (!$this->canReceiveContestNotification($customer, $merchant)) {
                continue;
            }

            $sent = $sendCallback($customer);
            if (!$sent) {
                $hasFailure = true;
            }
        }

        return !$hasFailure;
    }

    private function canReceiveContestNotification(Customer $customer, \App\Entity\Merchant $merchant): bool
    {
        $preference = $this->preferenceRepository->findOneByCustomerAndMerchant($customer, $merchant);

        if (!$preference instanceof CustomerMerchantNotificationPreference) {
            return true;
        }

        return $preference->isContestNotificationsEnabled();
    }

    private function countContestRecipients(Contest $contest): int
    {
        $merchant = $contest->getMerchant();
        if ($merchant === null) {
            return 0;
        }

        $participantIds = array_flip($this->participationRepository->findParticipantCustomerIds($contest));
        $customers = $this->customerRepository->findByMerchant($merchant);

        $count = 0;
        foreach ($customers as $customer) {
            $customerId = $customer->getId();
            if ($customerId !== null && isset($participantIds[$customerId])) {
                continue;
            }

            if (!$this->canReceiveContestNotification($customer, $merchant)) {
                continue;
            }

            $count++;
        }

        return $count;
    }
}
