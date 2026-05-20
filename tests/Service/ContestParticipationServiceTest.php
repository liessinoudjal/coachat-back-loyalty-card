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

    private function uniqueEmail(string $prefix): string
    {
        return sprintf('%s-%s@example.com', $prefix, bin2hex(random_bytes(4)));
    }

    public function testAutoEnrollsInActiveContests(): void
    {
        // Setup: Create merchant with active contest
        $user = new User();
        $user->setEmail($this->uniqueEmail('merchant'));
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
        $customer->setEmail($this->uniqueEmail('customer'));
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
        $card->setCurrentValue(0);
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
        $user->setEmail($this->uniqueEmail('merchant'));
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
        $customer->setEmail($this->uniqueEmail('customer'));
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
        $card->setCurrentValue(0);
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

        for ($i = 0; $i < ContestParticipationService::MAX_PARTICIPATIONS_PER_CUSTOMER; $i++) {
            $this->service->autoEnrollInActiveContests($transaction);
        }

        $countAtLimit = $this->participationRepository->countByContestAndCustomer($contest, $customer);
        $this->assertSame(ContestParticipationService::MAX_PARTICIPATIONS_PER_CUSTOMER, $countAtLimit);

        $this->service->autoEnrollInActiveContests($transaction);

        $countAfterExtraAttempt = $this->participationRepository->countByContestAndCustomer($contest, $customer);
        $this->assertSame(ContestParticipationService::MAX_PARTICIPATIONS_PER_CUSTOMER, $countAfterExtraAttempt);
    }

    public function testSkipsInactiveContests(): void
    {
        // Setup: Create inactive contest
        $user = new User();
        $user->setEmail($this->uniqueEmail('merchant'));
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
        $customer->setEmail($this->uniqueEmail('customer'));
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
        $card->setCurrentValue(0);
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
        $participationCount = $this->participationRepository->countByContestAndCustomer($inactiveContest, $customer);
        $this->assertSame(0, $participationCount);
    }

    public function testIsParticipating(): void
    {
        $user = new User();
        $user->setEmail($this->uniqueEmail('merchant'));
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
        $customer->setEmail($this->uniqueEmail('customer'));
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
        $otherCustomer->setEmail($this->uniqueEmail('other'));
        $this->entityManager->persist($otherCustomer);
        $this->entityManager->flush();

        $this->assertFalse($this->service->isParticipating($otherCustomer, $contest));
    }

    public function testAutoEnrollAddsMultipleParticipationsUntilLimit(): void
    {
        $user = new User();
        $user->setEmail($this->uniqueEmail('merchant'));
        $user->setPassword(password_hash('password', PASSWORD_BCRYPT));
        $user->setRoles(['ROLE_MERCHANT']);
        $this->entityManager->persist($user);

        $merchant = new Merchant();
        $merchant->setCompanyName('Test Merchant');
        $merchant->setUser($user);
        $this->entityManager->persist($merchant);

        $contest = new Contest();
        $contest->setMerchant($merchant);
        $contest->setTitle('Multi Participation Contest');
        $contest->setStatus(ContestStatus::ACTIVE);
        $contest->setStartAt(new \DateTimeImmutable('-1 day'));
        $contest->setEndAt(new \DateTimeImmutable('+2 days'));
        $this->entityManager->persist($contest);

        $customer = new Customer();
        $customer->setName('Customer');
        $customer->setEmail($this->uniqueEmail('customer'));
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
        $card->setCurrentValue(0);
        $this->entityManager->persist($card);
        $this->entityManager->flush();

        for ($i = 0; $i < ContestParticipationService::MAX_PARTICIPATIONS_PER_CUSTOMER + 2; $i++) {
            $transaction = new Transaction();
            $transaction->setMerchant($merchant);
            $transaction->setLoyaltyCard($card);
            $transaction->setPointsEarned(1);
            $transaction->setPointsRedeemed(0);
            $this->entityManager->persist($transaction);
            $this->entityManager->flush();

            $this->service->autoEnrollInActiveContests($transaction);
        }

        $participationCount = $this->participationRepository->countByContestAndCustomer($contest, $customer);
        $this->assertSame(ContestParticipationService::MAX_PARTICIPATIONS_PER_CUSTOMER, $participationCount);
    }

    public function testSkipsEndedContests(): void
    {
        $user = new User();
        $user->setEmail($this->uniqueEmail('merchant'));
        $user->setPassword(password_hash('password', PASSWORD_BCRYPT));
        $user->setRoles(['ROLE_MERCHANT']);
        $this->entityManager->persist($user);

        $merchant = new Merchant();
        $merchant->setCompanyName('Test Merchant');
        $merchant->setUser($user);
        $this->entityManager->persist($merchant);

        $now = new \DateTimeImmutable();
        $endedContest = new Contest();
        $endedContest->setMerchant($merchant);
        $endedContest->setTitle('Ended Contest');
        $endedContest->setStatus(ContestStatus::ACTIVE);
        $endedContest->setStartAt($now->modify('-10 days'));
        $endedContest->setEndAt($now->modify('-1 day'));
        $this->entityManager->persist($endedContest);

        $customer = new Customer();
        $customer->setName('Test Customer');
        $customer->setEmail($this->uniqueEmail('customer'));
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
        $card->setCurrentValue(0);
        $this->entityManager->persist($card);

        $this->entityManager->flush();

        $transaction = new Transaction();
        $transaction->setMerchant($merchant);
        $transaction->setLoyaltyCard($card);
        $transaction->setPointsEarned(1);
        $transaction->setPointsRedeemed(0);
        $this->entityManager->persist($transaction);
        $this->entityManager->flush();

        $this->service->autoEnrollInActiveContests($transaction);

        $count = $this->participationRepository->countByContestAndCustomer($endedContest, $customer);
        $this->assertSame(0, $count, 'A contest whose endAt is in the past must not enroll new participations.');
    }

    public function testDoesNotEnrollInOtherMerchantContests(): void
    {
        // Merchant A — owns the scanned card
        $userA = new User();
        $userA->setEmail($this->uniqueEmail('merchant-a'));
        $userA->setPassword(password_hash('password', PASSWORD_BCRYPT));
        $userA->setRoles(['ROLE_MERCHANT']);
        $this->entityManager->persist($userA);

        $merchantA = new Merchant();
        $merchantA->setCompanyName('Merchant A');
        $merchantA->setUser($userA);
        $this->entityManager->persist($merchantA);

        // Merchant B — has an unrelated active contest that should be ignored
        $userB = new User();
        $userB->setEmail($this->uniqueEmail('merchant-b'));
        $userB->setPassword(password_hash('password', PASSWORD_BCRYPT));
        $userB->setRoles(['ROLE_MERCHANT']);
        $this->entityManager->persist($userB);

        $merchantB = new Merchant();
        $merchantB->setCompanyName('Merchant B');
        $merchantB->setUser($userB);
        $this->entityManager->persist($merchantB);

        $now = new \DateTimeImmutable();
        $contestB = new Contest();
        $contestB->setMerchant($merchantB);
        $contestB->setTitle('Other Merchant Contest');
        $contestB->setStatus(ContestStatus::ACTIVE);
        $contestB->setStartAt($now->modify('-1 day'));
        $contestB->setEndAt($now->modify('+5 days'));
        $this->entityManager->persist($contestB);

        $customer = new Customer();
        $customer->setName('Test Customer');
        $customer->setEmail($this->uniqueEmail('customer'));
        $this->entityManager->persist($customer);

        $programA = new LoyaltyProgram();
        $programA->setMerchant($merchantA);
        $programA->setName('Program A');
        $programA->setType(LoyaltyProgramType::STAMP);
        $programA->setStampTarget(10);
        $this->entityManager->persist($programA);

        $card = new LoyaltyCard();
        $card->setMerchant($merchantA);
        $card->setCustomer($customer);
        $card->setLoyaltyProgram($programA);
        $card->setWalletToken('wallet-' . uniqid());
        $card->setCurrentValue(0);
        $this->entityManager->persist($card);

        $this->entityManager->flush();

        $transaction = new Transaction();
        $transaction->setMerchant($merchantA);
        $transaction->setLoyaltyCard($card);
        $transaction->setPointsEarned(1);
        $transaction->setPointsRedeemed(0);
        $this->entityManager->persist($transaction);
        $this->entityManager->flush();

        $this->service->autoEnrollInActiveContests($transaction);

        $count = $this->participationRepository->countByContestAndCustomer($contestB, $customer);
        $this->assertSame(0, $count, 'Scanning a card for merchant A must not enroll the customer in merchant B contests.');
    }
}
