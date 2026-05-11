<?php

namespace App\Tests\Service;

use App\Entity\Contest;
use App\Entity\ContestParticipation;
use App\Entity\Customer;
use App\Entity\LoyaltyCard;
use App\Entity\LoyaltyProgram;
use App\Entity\Merchant;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\ContestStatus;
use App\Enum\LoyaltyProgramType;
use App\Repository\ContestParticipationRepository;
use App\Repository\ContestRepository;
use App\Service\ContestParticipationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

class ContestParticipationServiceTest extends KernelTestCase
{
    private ContestParticipationService $service;
    private EntityManagerInterface $entityManager;
    private ContestRepository $contestRepository;
    private ContestParticipationRepository $participationRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->contestRepository = $this->entityManager->getRepository(Contest::class);
        $this->participationRepository = $this->entityManager->getRepository(ContestParticipation::class);
        $this->service = self::getContainer()->get(ContestParticipationService::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }

    public function testAutoEnrollsInActiveContests(): void
    {
        // Setup: Create merchant with active contest
        $user = new User();
        $user->setEmail('merchant@example.com');
        $user->setPassword(password_hash('password', PASSWORD_BCRYPT));
        $user->setRoles(['ROLE_MERCHANT']);
        $this->entityManager->persist($user);

        $merchant = new Merchant();
        $merchant->setCompanyName('Test Merchant');
        $merchant->setUser($user);
        $this->entityManager->persist($merchant);

        $now = new \DateTimeImmutable();
        $contest = new Contest();
        $contest->setMerchant($merchant);
        $contest->setTitle('Active Contest');
        $contest->setStatus(ContestStatus::ACTIVE);
        $contest->setStartAt($now->modify('-1 day'));
        $contest->setEndAt($now->modify('+5 days'));
        $this->entityManager->persist($contest);

        // Create customer and card
        $customer = new Customer();
        $customer->setName('Test Customer');
        $customer->setEmail('customer@example.com');
        $this->entityManager->persist($customer);

        $program = new LoyaltyProgram();
        $program->setMerchant($merchant);
        $program->setName('Test Program');
        $program->setType(LoyaltyProgramType::STAMP);
        $program->setStampTarget(10);
        $this->entityManager->persist($program);

        $card = new LoyaltyCard();
        $card->setMerchant($merchant);
        $card->setCustomer($customer);
        $card->setLoyaltyProgram($program);
        $card->setWalletToken('wallet-' . uniqid());
        $this->entityManager->persist($card);

        $this->entityManager->flush();

        // Create transaction
        $transaction = new Transaction();
        $transaction->setMerchant($merchant);
        $transaction->setLoyaltyCard($card);
        $transaction->setPointsEarned(1);
        $transaction->setPointsRedeemed(0);
        $this->entityManager->persist($transaction);
        $this->entityManager->flush();

        // Execute auto-enroll
        $this->service->autoEnrollInActiveContests($transaction);

        // Verify: Participation created
        $participation = $this->participationRepository->findByContestAndCustomer($contest, $customer);
        $this->assertNotNull($participation);
        $this->assertEquals($transaction, $participation->getTransaction());
        $this->assertFalse($participation->isWinningEntry());
    }

    public function testSkipsEnrollmentIfAlreadyParticipating(): void
    {
        // Setup: Customer already participating
        $user = new User();
        $user->setEmail('merchant@example.com');
        $user->setPassword(password_hash('password', PASSWORD_BCRYPT));
        $user->setRoles(['ROLE_MERCHANT']);
        $this->entityManager->persist($user);

        $merchant = new Merchant();
        $merchant->setCompanyName('Test Merchant');
        $merchant->setUser($user);
        $this->entityManager->persist($merchant);

        $now = new \DateTimeImmutable();
        $contest = new Contest();
        $contest->setMerchant($merchant);
        $contest->setTitle('Active Contest');
        $contest->setStatus(ContestStatus::ACTIVE);
        $contest->setStartAt($now->modify('-1 day'));
        $contest->setEndAt($now->modify('+5 days'));
        $this->entityManager->persist($contest);

        $customer = new Customer();
        $customer->setName('Test Customer');
        $customer->setEmail('customer@example.com');
        $this->entityManager->persist($customer);

        // Existing participation
        $existingParticipation = new ContestParticipation();
        $existingParticipation->setContest($contest);
        $existingParticipation->setCustomer($customer);
        $existingParticipation->setIsWinningEntry(false);
        $this->entityManager->persist($existingParticipation);

        $program = new LoyaltyProgram();
        $program->setMerchant($merchant);
        $program->setName('Test Program');
        $program->setType(LoyaltyProgramType::STAMP);
        $program->setStampTarget(10);
        $this->entityManager->persist($program);

        $card = new LoyaltyCard();
        $card->setMerchant($merchant);
        $card->setCustomer($customer);
        $card->setLoyaltyProgram($program);
        $card->setWalletToken('wallet-' . uniqid());
        $this->entityManager->persist($card);

        $this->entityManager->flush();

        // Create transaction
        $transaction = new Transaction();
        $transaction->setMerchant($merchant);
        $transaction->setLoyaltyCard($card);
        $transaction->setPointsEarned(1);
        $transaction->setPointsRedeemed(0);
        $this->entityManager->persist($transaction);
        $this->entityManager->flush();

        // Get count before
        $countBefore = $this->participationRepository->countParticipations($contest);

        // Execute auto-enroll
        $this->service->autoEnrollInActiveContests($transaction);

        // Verify: No new participation created
        $countAfter = $this->participationRepository->countParticipations($contest);
        $this->assertEquals($countBefore, $countAfter);
    }

    public function testSkipsInactiveContests(): void
    {
        // Setup: Create inactive contest
        $user = new User();
        $user->setEmail('merchant@example.com');
        $user->setPassword(password_hash('password', PASSWORD_BCRYPT));
        $user->setRoles(['ROLE_MERCHANT']);
        $this->entityManager->persist($user);

        $merchant = new Merchant();
        $merchant->setCompanyName('Test Merchant');
        $merchant->setUser($user);
        $this->entityManager->persist($merchant);

        $now = new \DateTimeImmutable();
        $inactiveContest = new Contest();
        $inactiveContest->setMerchant($merchant);
        $inactiveContest->setTitle('Future Contest');
        $inactiveContest->setStatus(ContestStatus::SCHEDULED);
        $inactiveContest->setStartAt($now->modify('+5 days')); // Future start
        $inactiveContest->setEndAt($now->modify('+10 days'));
        $this->entityManager->persist($inactiveContest);

        $customer = new Customer();
        $customer->setName('Test Customer');
        $customer->setEmail('customer@example.com');
        $this->entityManager->persist($customer);

        $program = new LoyaltyProgram();
        $program->setMerchant($merchant);
        $program->setName('Test Program');
        $program->setType(LoyaltyProgramType::STAMP);
        $program->setStampTarget(10);
        $this->entityManager->persist($program);

        $card = new LoyaltyCard();
        $card->setMerchant($merchant);
        $card->setCustomer($customer);
        $card->setLoyaltyProgram($program);
        $card->setWalletToken('wallet-' . uniqid());
        $this->entityManager->persist($card);

        $this->entityManager->flush();

        // Create transaction
        $transaction = new Transaction();
        $transaction->setMerchant($merchant);
        $transaction->setLoyaltyCard($card);
        $transaction->setPointsEarned(1);
        $transaction->setPointsRedeemed(0);
        $this->entityManager->persist($transaction);
        $this->entityManager->flush();

        // Execute auto-enroll
        $this->service->autoEnrollInActiveContests($transaction);

        // Verify: No participation created
        $participation = $this->participationRepository->findByContestAndCustomer($inactiveContest, $customer);
        $this->assertNull($participation);
    }

    public function testIsParticipating(): void
    {
        $user = new User();
        $user->setEmail('merchant@example.com');
        $user->setPassword(password_hash('password', PASSWORD_BCRYPT));
        $user->setRoles(['ROLE_MERCHANT']);
        $this->entityManager->persist($user);

        $merchant = new Merchant();
        $merchant->setCompanyName('Test');
        $merchant->setUser($user);
        $this->entityManager->persist($merchant);

        $contest = new Contest();
        $contest->setMerchant($merchant);
        $contest->setTitle('Test');
        $contest->setStatus(ContestStatus::ACTIVE);
        $contest->setStartAt(new \DateTimeImmutable('-1 day'));
        $contest->setEndAt(new \DateTimeImmutable('+1 day'));
        $this->entityManager->persist($contest);

        $customer = new Customer();
        $customer->setName('Test');
        $customer->setEmail('test@example.com');
        $this->entityManager->persist($customer);

        $participation = new ContestParticipation();
        $participation->setContest($contest);
        $participation->setCustomer($customer);
        $this->entityManager->persist($participation);

        $this->entityManager->flush();

        // Verify
        $this->assertTrue($this->service->isParticipating($customer, $contest));

        $otherCustomer = new Customer();
        $otherCustomer->setName('Other');
        $otherCustomer->setEmail('other@example.com');
        $this->entityManager->persist($otherCustomer);
        $this->entityManager->flush();

        $this->assertFalse($this->service->isParticipating($otherCustomer, $contest));
    }
}
