<?php

namespace App\Tests\Controller;

use App\Entity\Contest;
use App\Entity\ContestParticipation;
use App\Entity\ContestReward;
use App\Entity\ContestWinner;
use App\Entity\Customer;
use App\Entity\Merchant;
use App\Entity\User;
use App\Enum\ContestStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

class MerchantContestControllerTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private User $merchantUser;
    private Merchant $merchant;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        // Create merchant and user
        $this->merchant = new Merchant();
        $this->merchant->setCompanyName('Test Merchant');
        $this->entityManager->persist($this->merchant);

        $this->merchantUser = new User();
        $this->merchantUser->setEmail('merchant@example.com');
        $this->merchantUser->setPassword(password_hash('password', PASSWORD_BCRYPT));
        $this->merchantUser->setRoles(['ROLE_MERCHANT']);
        $this->merchantUser->setMerchant($this->merchant);
        $this->entityManager->persist($this->merchantUser);

        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }

    private function login(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/auth/login', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email' => 'merchant@example.com',
            'password' => 'password',
        ]));

        $this->assertResponseStatusCodeSame(200);
    }

    public function testListContests(): void
    {
        $client = self::createClient();
        $this->login();

        // Create test contest
        $contest = new Contest();

        $contest->setMerchant($this->merchant);
        $contest->setTitle('Test Contest');
        $contest->setStatus(ContestStatus::DRAFT);
        $contest->setStartAt(new \DateTimeImmutable('+1 day'));
        $contest->setEndAt(new \DateTimeImmutable('+8 days'));
        $this->entityManager->persist($contest);
        $this->entityManager->flush();

        $client->request('GET', '/api/merchants/me/contests');
        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('member', $data);
        $this->assertCount(1, $data['member']);
        $this->assertEquals('Test Contest', $data['member'][0]['title']);
    }

    public function testCreateContest(): void
    {
        $client = self::createClient();
        $this->login();

        $payload = [
            'title' => 'New Contest',
            'description' => 'A test contest',
            'start_at' => (new \DateTimeImmutable('+1 day'))->format('c'),
            'end_at' => (new \DateTimeImmutable('+8 days'))->format('c'),
            'rewards' => [
                ['title' => 'First Prize', 'image_url' => 'https://example.com/prize1.jpg'],
                ['title' => 'Second Prize', 'image_url' => null],
            ],
        ];

        $client->request('POST', '/api/merchants/me/contests', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
        $this->assertResponseStatusCodeSame(201);

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals('New Contest', $data['title']);
        $this->assertEquals('draft', $data['status']);
        $this->assertCount(2, $data['rewards']);
    }

    public function testCreateContestValidation(): void
    {
        $client = self::createClient();
        $this->login();

        // Missing title
        $payload = [
            'title' => '',
            'start_at' => (new \DateTimeImmutable('+1 day'))->format('c'),
            'end_at' => (new \DateTimeImmutable('+8 days'))->format('c'),
            'rewards' => [['title' => 'Prize']],
        ];

        $client->request('POST', '/api/merchants/me/contests', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
        $this->assertResponseStatusCodeSame(422);

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertEquals('contest_title_required', $data['error']);
    }

    public function testUpdateContest(): void
    {
        $client = self::createClient();
        $this->login();

        // Create contest
        $contest = new Contest();

        $contest->setMerchant($this->merchant);
        $contest->setTitle('Original Title');
        $contest->setStatus(ContestStatus::DRAFT);
        $contest->setStartAt(new \DateTimeImmutable('+1 day'));
        $contest->setEndAt(new \DateTimeImmutable('+8 days'));

        $reward = new ContestReward();
        $reward->setContest($contest);
        $reward->setTitle('Prize');
        $reward->setRank(1);
        $contest->addReward($reward);

        $this->entityManager->persist($contest);
        $this->entityManager->flush();

        $payload = [
            'title' => 'Updated Title',
            'description' => 'New description',
        ];

        $client->request('PUT', "/api/merchants/me/contests/{$contest->getId()->toRfc4122()}", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
        $this->assertResponseStatusCodeSame(200);

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals('Updated Title', $data['title']);
        $this->assertEquals('New description', $data['description']);
    }

    public function testUpdateContestLockedAfterStart(): void
    {
        $client = self::createClient();
        $this->login();

        // Create active contest
        $contest = new Contest();

        $contest->setMerchant($this->merchant);
        $contest->setTitle('Active Contest');
        $contest->setStatus(ContestStatus::ACTIVE);
        $contest->setStartAt(new \DateTimeImmutable('-1 day'));
        $contest->setEndAt(new \DateTimeImmutable('+8 days'));

        $reward = new ContestReward();
        $reward->setContest($contest);
        $reward->setTitle('Prize');
        $reward->setRank(1);
        $contest->addReward($reward);

        $this->entityManager->persist($contest);
        $this->entityManager->flush();

        $payload = ['title' => 'New Title'];

        $client->request('PUT', "/api/merchants/me/contests/{$contest->getId()->toRfc4122()}", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
        $this->assertResponseStatusCodeSame(409);

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals('contest_edit_locked', $data['error']);
    }

    public function testDeleteContest(): void
    {
        $client = self::createClient();
        $this->login();

        // Create contest
        $contest = new Contest();

        $contest->setMerchant($this->merchant);
        $contest->setTitle('To Delete');
        $contest->setStatus(ContestStatus::DRAFT);
        $contest->setStartAt(new \DateTimeImmutable('+1 day'));
        $contest->setEndAt(new \DateTimeImmutable('+8 days'));

        $this->entityManager->persist($contest);
        $this->entityManager->flush();

        $client->request('DELETE', "/api/merchants/me/contests/{$contest->getId()->toRfc4122()}");
        $this->assertResponseStatusCodeSame(204);

        // Verify status changed to finished
        $updatedContest = $this->entityManager->getRepository(Contest::class)->find($contest->getId());
        $this->assertEquals(ContestStatus::FINISHED, $updatedContest->getStatus());
    }

    public function testCloneContest(): void
    {
        $client = self::createClient();
        $this->login();

        // Create contest
        $contest = new Contest();

        $contest->setMerchant($this->merchant);
        $contest->setTitle('Original');
        $contest->setDescription('Original description');
        $contest->setStatus(ContestStatus::FINISHED);
        $contest->setStartAt(new \DateTimeImmutable('-10 days'));
        $contest->setEndAt(new \DateTimeImmutable('-2 days'));

        $reward = new ContestReward();
        $reward->setContest($contest);
        $reward->setTitle('Prize');
        $reward->setImageUrl('https://example.com/prize.jpg');
        $reward->setRank(1);
        $contest->addReward($reward);

        $this->entityManager->persist($contest);
        $this->entityManager->flush();

        $client->request('POST', "/api/merchants/me/contests/{$contest->getId()->toRfc4122()}/clone");
        $this->assertResponseStatusCodeSame(201);

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('copie', $data['title']);
        $this->assertEquals('draft', $data['status']);
        $this->assertCount(1, $data['rewards']);
        $this->assertEquals('Prize', $data['rewards'][0]['title']);
    }

    public function testDrawContest(): void
    {
        $client = self::createClient();
        $this->login();

        // Create contest
        $contest = new Contest();

        $contest->setMerchant($this->merchant);
        $contest->setTitle('Draw Contest');
        $contest->setStatus(ContestStatus::ACTIVE);
        $contest->setStartAt(new \DateTimeImmutable('-1 day'));
        $contest->setEndAt(new \DateTimeImmutable('+1 day'));
        $contest->setDrawAt(new \DateTimeImmutable('-1 hour'));
        $this->entityManager->persist($contest);

        // Create rewards
        $reward1 = new ContestReward();
        $reward1->setContest($contest);
        $reward1->setTitle('First Prize');
        $reward1->setRank(1);
        $this->entityManager->persist($reward1);

        // Create customers and participations
        for ($i = 0; $i < 3; $i++) {
            $customer = new Customer();
            $customer->setName("Customer $i");
            $customer->setEmail("customer$i@example.com");
            $this->entityManager->persist($customer);

            $participation = new ContestParticipation();
            $participation->setContest($contest);
            $participation->setCustomer($customer);
            $participation->setIsWinningEntry(false);
            $this->entityManager->persist($participation);
        }

        $this->entityManager->flush();

        $client->request('POST', "/api/merchants/me/contests/{$contest->getId()->toRfc4122()}/draw");
        $this->assertResponseStatusCodeSame(200);

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('winners', $data);
        $this->assertCount(1, $data['winners']);
        $this->assertStringStartsWith('contest_reward:', $data['winners'][0]['qr_token']);
    }

    public function testDrawContestNotReady(): void
    {
        $client = self::createClient();
        $this->login();

        // Create contest with future draw date
        $contest = new Contest();

        $contest->setMerchant($this->merchant);
        $contest->setTitle('Future Draw Contest');
        $contest->setStatus(ContestStatus::ACTIVE);
        $contest->setStartAt(new \DateTimeImmutable('-1 day'));
        $contest->setEndAt(new \DateTimeImmutable('+1 day'));
        $contest->setDrawAt(new \DateTimeImmutable('+1 hour')); // Future draw
        $this->entityManager->persist($contest);
        $this->entityManager->flush();

        $client->request('POST', "/api/merchants/me/contests/{$contest->getId()->toRfc4122()}/draw");
        $this->assertResponseStatusCodeSame(409);

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals('contest_draw_not_ready', $data['error']);
    }

    public function testClaimRewardByQr(): void
    {
        $client = self::createClient();
        $this->login();

        // Create contest with winner
        $contest = new Contest();

        $contest->setMerchant($this->merchant);
        $contest->setTitle('Claim Test');
        $contest->setStatus(ContestStatus::ACTIVE);
        $contest->setStartAt(new \DateTimeImmutable('-1 day'));
        $contest->setEndAt(new \DateTimeImmutable('+1 day'));
        $this->entityManager->persist($contest);

        $reward = new ContestReward();
        $reward->setContest($contest);
        $reward->setTitle('Prize');
        $reward->setRank(1);
        $this->entityManager->persist($reward);

        $customer = new Customer();
        $customer->setName('Winner');
        $customer->setEmail('winner@example.com');
        $this->entityManager->persist($customer);

        $winner = new ContestWinner();
        $winner->setContest($contest);
        $winner->setCustomer($customer);
        $winner->setReward($reward);
        $winner->setQrCodeToken('contest_reward:test12345');
        $winner->setIsClaimed(false);
        $this->entityManager->persist($winner);

        $this->entityManager->flush();

        $payload = ['qr_token' => 'contest_reward:test12345'];

        $client->request('POST', '/api/contest-winners/claim-by-qr', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
        $this->assertResponseStatusCodeSame(200);

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['is_claimed']);
        $this->assertEquals('Winner', $data['customer_name']);
        $this->assertEquals('Prize', $data['reward_title']);
    }

    public function testClaimRewardByQrNotFound(): void
    {
        $client = self::createClient();
        $this->login();

        $payload = ['qr_token' => 'contest_reward:nonexistent'];

        $client->request('POST', '/api/contest-winners/claim-by-qr', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
        $this->assertResponseStatusCodeSame(404);

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals('winner_not_found', $data['error']);
    }

    public function testClaimRewardByQrAlreadyClaimed(): void
    {
        $client = self::createClient();
        $this->login();

        // Create already claimed winner
        $contest = new Contest();

        $contest->setMerchant($this->merchant);
        $contest->setTitle('Already Claimed');
        $contest->setStatus(ContestStatus::ACTIVE);
        $contest->setStartAt(new \DateTimeImmutable('-1 day'));
        $contest->setEndAt(new \DateTimeImmutable('+1 day'));
        $this->entityManager->persist($contest);

        $reward = new ContestReward();
        $reward->setContest($contest);
        $reward->setTitle('Prize');
        $reward->setRank(1);
        $this->entityManager->persist($reward);

        $customer = new Customer();
        $customer->setName('Winner');
        $customer->setEmail('winner@example.com');
        $this->entityManager->persist($customer);

        $winner = new ContestWinner();
        $winner->setContest($contest);
        $winner->setCustomer($customer);
        $winner->setReward($reward);
        $winner->setQrCodeToken('contest_reward:test99999');
        $winner->setIsClaimed(true);
        $winner->setClaimedAt(new \DateTimeImmutable());
        $this->entityManager->persist($winner);

        $this->entityManager->flush();

        $payload = ['qr_token' => 'contest_reward:test99999'];

        $client->request('POST', '/api/contest-winners/claim-by-qr', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
        $this->assertResponseStatusCodeSame(409);

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals('reward_already_claimed', $data['error']);
    }
}
