<?php

namespace App\Tests\Service;

use App\Entity\Contest;
use App\Entity\ContestParticipation;
use App\Entity\ContestReward;
use App\Entity\ContestWinner;
use App\Entity\Customer;
use App\Entity\Merchant;
use App\Entity\User;
use App\Enum\ContestStatus;
use App\Repository\ContestParticipationRepository;
use App\Repository\ContestRewardRepository;
use App\Repository\ContestWinnerRepository;
use App\Service\ContestDrawService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

class ContestDrawServiceTest extends KernelTestCase
{
    private ContestDrawService $service;
    private EntityManagerInterface $entityManager;
    private ContestParticipationRepository $participationRepository;
    private ContestRewardRepository $rewardRepository;
    private ContestWinnerRepository $winnerRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->participationRepository = $this->entityManager->getRepository(ContestParticipation::class);
        $this->rewardRepository = $this->entityManager->getRepository(ContestReward::class);
        $this->winnerRepository = $this->entityManager->getRepository(ContestWinner::class);
        $this->service = self::getContainer()->get(ContestDrawService::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }

    public function testDrawExecutesFairlyDistributesRewards(): void
    {
        // Setup: Create merchant, contest, and participants
        $user = new User();
        $user->setEmail('merchant@example.com');
        $user->setPassword(password_hash('password', PASSWORD_BCRYPT));
        $user->setRoles(['ROLE_MERCHANT']);
        $this->entityManager->persist($user);

        $merchant = new Merchant();
        $merchant->setCompanyName('Test Merchant');
        $merchant->setUser($user);
        $this->entityManager->persist($merchant);

        $contest = new Contest();
        $contest->setMerchant($merchant);
        $contest->setTitle('Test Contest');
        $contest->setStatus(ContestStatus::ACTIVE);
        $contest->setStartAt(new \DateTimeImmutable('-1 day'));
        $contest->setEndAt(new \DateTimeImmutable('+1 day'));
        $contest->setDrawAt(new \DateTimeImmutable('-1 hour')); // Can draw now
        $this->entityManager->persist($contest);

        // Create rewards
        $reward1 = new ContestReward();
        $reward1->setContest($contest);
        $reward1->setTitle('First Prize');
        $reward1->setRank(1);
        $this->entityManager->persist($reward1);

        $reward2 = new ContestReward();
        $reward2->setContest($contest);
        $reward2->setTitle('Second Prize');
        $reward2->setRank(2);
        $this->entityManager->persist($reward2);

        // Create customers and participations
        $customers = [];
        for ($i = 0; $i < 5; $i++) {
            $customer = new Customer();
            $customer->setName("Customer $i");
            $customer->setEmail("customer$i@example.com");
            $this->entityManager->persist($customer);
            $customers[] = $customer;
        }

        $this->entityManager->flush();

        // Create participations
        foreach ($customers as $customer) {
            $participation = new ContestParticipation();
            $participation->setContest($contest);
            $participation->setCustomer($customer);
            $participation->setIsWinningEntry(false);
            $this->entityManager->persist($participation);
        }
        $this->entityManager->flush();

        // Execute draw
        $winners = $this->service->executeDraw($contest);

        // Verify: Correct number of winners
        $this->assertCount(2, $winners);

        // Verify: Winners have correct rewards
        $this->assertEquals(1, $winners[0]->getReward()->getRank());
        $this->assertEquals(2, $winners[1]->getReward()->getRank());

        // Verify: QR tokens are generated
        $this->assertStringStartsWith('contest_reward:', $winners[0]->getQrCodeToken());
        $this->assertStringStartsWith('contest_reward:', $winners[1]->getQrCodeToken());

        // Verify: Different QR tokens
        $this->assertNotEquals($winners[0]->getQrCodeToken(), $winners[1]->getQrCodeToken());

        // Verify: Participations marked as winning
        $markedParticipations = $this->participationRepository->findBy(['isWinningEntry' => true]);
        $this->assertCount(2, $markedParticipations);
    }

    public function testDrawFailsWithNoEligibleParticipants(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No eligible participants for draw');

        $user = new User();
        $user->setEmail('merchant@example.com');
        $user->setPassword(password_hash('password', PASSWORD_BCRYPT));
        $user->setRoles(['ROLE_MERCHANT']);
        $this->entityManager->persist($user);

        $merchant = new Merchant();
        $merchant->setCompanyName('Test Merchant');
        $merchant->setUser($user);
        $this->entityManager->persist($merchant);

        $contest = new Contest();
        $contest->setMerchant($merchant);
        $contest->setTitle('Empty Contest');
        $contest->setStatus(ContestStatus::ACTIVE);
        $contest->setStartAt(new \DateTimeImmutable('-1 day'));
        $contest->setEndAt(new \DateTimeImmutable('+1 day'));
        $contest->setDrawAt(new \DateTimeImmutable('-1 hour'));
        $this->entityManager->persist($contest);

        $reward = new ContestReward();
        $reward->setContest($contest);
        $reward->setTitle('Prize');
        $reward->setRank(1);
        $this->entityManager->persist($reward);

        $this->entityManager->flush();

        // Should fail: no participants
        $this->service->executeDraw($contest);
    }

    public function testDrawFailsWithNoRewards(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Contest has no rewards to distribute');

        $user = new User();
        $user->setEmail('merchant@example.com');
        $user->setPassword(password_hash('password', PASSWORD_BCRYPT));
        $user->setRoles(['ROLE_MERCHANT']);
        $this->entityManager->persist($user);

        $merchant = new Merchant();
        $merchant->setCompanyName('Test Merchant');
        $merchant->setUser($user);
        $this->entityManager->persist($merchant);

        $contest = new Contest();
        $contest->setMerchant($merchant);
        $contest->setTitle('No Rewards Contest');
        $contest->setStatus(ContestStatus::ACTIVE);
        $contest->setStartAt(new \DateTimeImmutable('-1 day'));
        $contest->setEndAt(new \DateTimeImmutable('+1 day'));
        $contest->setDrawAt(new \DateTimeImmutable('-1 hour'));
        $this->entityManager->persist($contest);

        $customer = new Customer();
        $customer->setName('Customer 1');
        $customer->setEmail('customer@example.com');
        $this->entityManager->persist($customer);

        $participation = new ContestParticipation();
        $participation->setContest($contest);
        $participation->setCustomer($customer);
        $participation->setIsWinningEntry(false);
        $this->entityManager->persist($participation);

        $this->entityManager->flush();

        // Should fail: no rewards
        $this->service->executeDraw($contest);
    }

    public function testResetDrawRemovesAllWinners(): void
    {
        // Setup: Create contest with winners
        $user = new User();
        $user->setEmail('merchant@example.com');
        $user->setPassword(password_hash('password', PASSWORD_BCRYPT));
        $user->setRoles(['ROLE_MERCHANT']);
        $this->entityManager->persist($user);

        $merchant = new Merchant();
        $merchant->setCompanyName('Test Merchant');
        $merchant->setUser($user);
        $this->entityManager->persist($merchant);

        $contest = new Contest();
        $contest->setMerchant($merchant);
        $contest->setTitle('Test Contest');
        $contest->setStatus(ContestStatus::ACTIVE);
        $contest->setStartAt(new \DateTimeImmutable('-1 day'));
        $contest->setEndAt(new \DateTimeImmutable('+1 day'));
        $contest->setDrawAt(new \DateTimeImmutable('-1 hour'));
        $this->entityManager->persist($contest);

        $reward = new ContestReward();
        $reward->setContest($contest);
        $reward->setTitle('Prize');
        $reward->setRank(1);
        $this->entityManager->persist($reward);

        $customer = new Customer();
        $customer->setName('Customer');
        $customer->setEmail('customer@example.com');
        $this->entityManager->persist($customer);

        $participation = new ContestParticipation();
        $participation->setContest($contest);
        $participation->setCustomer($customer);
        $participation->setIsWinningEntry(true);
        $this->entityManager->persist($participation);

        $winner = new ContestWinner();
        $winner->setContest($contest);
        $winner->setCustomer($customer);
        $winner->setReward($reward);
        $winner->setQrCodeToken('contest_reward:test');
        $this->entityManager->persist($winner);

        $this->entityManager->flush();

        // Execute reset
        $this->service->resetDraw($contest);

        // Verify: Winners removed
        $remainingWinners = $this->winnerRepository->findBy(['contest' => $contest]);
        $this->assertCount(0, $remainingWinners);

        // Verify: Participations reset
        $markedParticipations = $this->participationRepository->findBy(['isWinningEntry' => true]);
        $this->assertCount(0, $markedParticipations);
    }
}
