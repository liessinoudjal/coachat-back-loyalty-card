<?php

namespace App\Tests\Controller;

use App\Entity\Contest;
use App\Entity\ContestReward;
use App\Entity\Merchant;
use App\Entity\User;
use App\Enum\ContestStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ContestDateValidationTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private Merchant $merchant;
    private User $merchantUser;

    public function testCreateContestWithEndBeforeStart(): void
    {
        $client = static::createClient();
        $this->setupMerchant();
        $this->loginUser($client);

        $startDate = new \DateTimeImmutable('+5 days');
        $endDate = new \DateTimeImmutable('+2 days');

        $payload = [
            'title' => 'Invalid Contest',
            'start_at' => $startDate->format('c'),
            'end_at' => $endDate->format('c'),
            'rewards' => [['title' => 'Prize']],
        ];

        $client->request('POST', '/api/merchants/me/contests', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
        $this->assertResponseStatusCodeSame(422);

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals('contest_end_before_start', $data['error']);
    }

    public function testCreateContestWithDrawBeforeEnd(): void
    {
        $client = static::createClient();
        $this->setupMerchant();
        $this->loginUser($client);

        $startDate = new \DateTimeImmutable('+1 day');
        $endDate = new \DateTimeImmutable('+5 days');
        $drawDate = new \DateTimeImmutable('+3 days');

        $payload = [
            'title' => 'Invalid Draw Contest',
            'start_at' => $startDate->format('c'),
            'end_at' => $endDate->format('c'),
            'draw_at' => $drawDate->format('c'),
            'rewards' => [['title' => 'Prize']],
        ];

        $client->request('POST', '/api/merchants/me/contests', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
        $this->assertResponseStatusCodeSame(422);

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals('contest_draw_before_end', $data['error']);
    }

    public function testCreateContestWithDrawEqualToEnd(): void
    {
        $client = static::createClient();
        $this->setupMerchant();
        $this->loginUser($client);

        $startDate = new \DateTimeImmutable('+1 day');
        $endDate = new \DateTimeImmutable('+5 days 14:30:00');
        $drawDate = $endDate;

        $payload = [
            'title' => 'Valid Draw Contest',
            'start_at' => $startDate->format('c'),
            'end_at' => $endDate->format('c'),
            'draw_at' => $drawDate->format('c'),
            'rewards' => [['title' => 'Prize']],
        ];

        $client->request('POST', '/api/merchants/me/contests', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
        $this->assertResponseStatusCodeSame(201);

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals('Valid Draw Contest', $data['title']);
    }

    public function testCreateContestPreservesEndTime(): void
    {
        $client = static::createClient();
        $this->setupMerchant();
        $this->loginUser($client);

        $startDate = new \DateTimeImmutable('+1 day 10:00:00');
        $endDate = new \DateTimeImmutable('+5 days 18:45:30');
        $drawDate = new \DateTimeImmutable('+6 days 09:00:00');

        $payload = [
            'title' => 'Timed Contest',
            'start_at' => $startDate->format('c'),
            'end_at' => $endDate->format('c'),
            'draw_at' => $drawDate->format('c'),
            'rewards' => [['title' => 'Prize']],
        ];

        $client->request('POST', '/api/merchants/me/contests', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
        $this->assertResponseStatusCodeSame(201);

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals('Timed Contest', $data['title']);

        // Verify times are preserved
        $endDateTime = new \DateTimeImmutable($data['end_at']);
        $this->assertEquals(18, (int) $endDateTime->format('H'));
        $this->assertEquals(45, (int) $endDateTime->format('i'));

        $drawDateTime = new \DateTimeImmutable($data['draw_at']);
        $this->assertEquals(9, (int) $drawDateTime->format('H'));
        $this->assertEquals(0, (int) $drawDateTime->format('i'));
    }

    private function setupMerchant(): void
    {
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        
        $uniqueId = bin2hex(random_bytes(4));
        
        $this->merchant = new Merchant();
        $this->merchant->setCompanyName('Test Merchant');
        $this->entityManager->persist($this->merchant);

        $this->merchantUser = new User();
        $this->merchantUser->setEmail('merchant-' . $uniqueId . '@example.com');
        $this->merchantUser->setPassword(password_hash('password', PASSWORD_BCRYPT));
        $this->merchantUser->setRoles(['ROLE_MERCHANT']);
        $this->merchantUser->setMerchant($this->merchant);
        $this->entityManager->persist($this->merchantUser);

        $this->entityManager->flush();
    }

    private function loginUser($client): void
    {
        $client->request('POST', '/api/auth/login', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email' => $this->merchantUser->getEmail(),
            'password' => 'password',
        ]));

        $this->assertResponseStatusCodeSame(200);
    }
}
