<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\LoyaltyProgram;
use App\Entity\MerchantGoogleReviewModule;
use App\Entity\Merchant;
use App\Entity\User;
use App\Enum\LoyaltyProgramType;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SuperAdminControllerTest extends WebTestCase
{
    public function testSuperAdminCanReadOwnProfile(): void
    {
        $client = static::createClient();

        $user = $this->createUser('profile', ['ROLE_USER', 'ROLE_SUPER_ADMIN']);

        $client->request('GET', '/api/super-admin/me', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->createJwtFor($user),
        ]);

        self::assertResponseStatusCodeSame(200);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame($user->getEmail(), $payload['email']);
        self::assertTrue($payload['is_super_admin']);
        self::assertContains('ROLE_SUPER_ADMIN', $payload['roles']);
        self::assertNull($payload['google_id']);
        self::assertNull($payload['merchant']);
    }

    public function testSuperAdminCanListAllMerchants(): void
    {
        $client = static::createClient();

        $superAdmin = $this->createUser('list-admin', ['ROLE_USER', 'ROLE_SUPER_ADMIN']);

        $merchantUserA = $this->createUser('merchant-a', ['ROLE_USER', 'ROLE_MERCHANT']);
        $this->createMerchant($merchantUserA, 'Alpha Shop', 'Paris');

        $merchantUserB = $this->createUser('merchant-b', ['ROLE_USER', 'ROLE_MERCHANT']);
        $this->createMerchant($merchantUserB, 'Beta Shop', 'Bordeaux');

        $client->request('GET', '/api/super-admin/merchants', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->createJwtFor($superAdmin),
        ]);

        self::assertResponseStatusCodeSame(200);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertGreaterThanOrEqual(2, $payload['total']);
        self::assertGreaterThanOrEqual(2, count($payload['items']));

        $companyNames = array_column($payload['items'], 'company_name');
        self::assertContains('Alpha Shop', $companyNames);
        self::assertContains('Beta Shop', $companyNames);

        $alphaIndex = array_search('Alpha Shop', $companyNames, true);
        self::assertNotFalse($alphaIndex);
        self::assertIsInt($alphaIndex);
        self::assertArrayHasKey('subscription_status', $payload['items'][$alphaIndex]);
        self::assertArrayHasKey('trial_ends_at', $payload['items'][$alphaIndex]);
        self::assertArrayHasKey('plan', $payload['items'][$alphaIndex]);
        self::assertArrayHasKey('user', $payload['items'][$alphaIndex]);
        self::assertArrayHasKey('loyalty_program_count', $payload['items'][$alphaIndex]);
        self::assertArrayHasKey('loyalty_card_count', $payload['items'][$alphaIndex]);
        self::assertArrayHasKey('transaction_count', $payload['items'][$alphaIndex]);
        self::assertArrayHasKey('reward_count', $payload['items'][$alphaIndex]);
    }

    public function testMerchantCannotAccessSuperAdminRoutes(): void
    {
        $client = static::createClient();

        $merchantUser = $this->createUser('plain-merchant', ['ROLE_USER', 'ROLE_MERCHANT']);
        $this->createMerchant($merchantUser, 'Plain Merchant', 'Nantes');

        $client->request('GET', '/api/super-admin/me', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->createJwtFor($merchantUser),
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testSuperAdminCanCreateUnclaimedMerchantWithoutEmail(): void
    {
        if (!$this->isMerchantUserIdNullable()) {
            self::markTestSkipped('La colonne merchant.user_id est NOT NULL dans la base de test. Appliquer les migrations pour tester le flux non réclamé.');
        }

        $client = static::createClient();

        $superAdmin = $this->createUser('create-unclaimed-no-email', ['ROLE_USER', 'ROLE_SUPER_ADMIN']);

        $client->request('POST', '/api/super-admin/merchants', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->createJwtFor($superAdmin),
        ], content: json_encode([
            'company_name' => 'Ghost Merchant',
            'address' => '10 rue des Tests',
            'postal_code' => '45000',
            'city' => 'Orleans',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('Ghost Merchant', $payload['company_name']);
        self::assertNull($payload['email']);
        self::assertFalse($payload['is_claimed']);
        self::assertNull($payload['user']);
    }

    public function testSuperAdminCannotClearEmailOnClaimedMerchant(): void
    {
        $client = static::createClient();

        $superAdmin = $this->createUser('clear-claimed-email-admin', ['ROLE_USER', 'ROLE_SUPER_ADMIN']);
        $merchantUser = $this->createUser('clear-claimed-email-owner', ['ROLE_USER', 'ROLE_MERCHANT']);
        $merchant = $this->createMerchant($merchantUser, 'Claimed Shop', 'Tours');

        $client->request('PUT', '/api/super-admin/merchants/' . $merchant->getId()?->toRfc4122() . '/claim-email', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->createJwtFor($superAdmin),
        ], content: json_encode([
            'email' => '',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(409);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('claimed_merchant_email_required', $payload['error']);
    }

    public function testSuperAdminInheritsMerchantPermissions(): void
    {
        $client = static::createClient();

        $user = $this->createUser('merchant-inheritance', ['ROLE_USER', 'ROLE_SUPER_ADMIN']);
        $merchant = $this->createMerchant($user, 'Inherited Merchant', 'Lille');

        $client->request('GET', '/api/merchants/me/google-review-module', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->createJwtFor($user),
        ]);

        self::assertResponseStatusCodeSame(200);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($merchant->getId()?->toRfc4122(), $payload['merchant_id']);
        self::assertSame('Inherited Merchant', $payload['merchant_name']);
    }

    public function testSuperAdminCanReadMerchantLoyaltyProgramsPresenterData(): void
    {
        $client = static::createClient();

        $superAdmin = $this->createUser('program-reader', ['ROLE_USER', 'ROLE_SUPER_ADMIN']);

        $merchantUser = $this->createUser('program-target', ['ROLE_USER', 'ROLE_MERCHANT']);
        $merchant = $this->createMerchant($merchantUser, 'Target Shop', 'Lyon');
        $this->createLoyaltyProgram($merchant, 'Coffee Program', LoyaltyProgramType::STAMP, null, null, 10, '1 cafe offert');
        $this->createLoyaltyProgram($merchant, 'Points Program', LoyaltyProgramType::POINTS, 2, 100, null, '5 euros offerts');

        $client->request('GET', '/api/super-admin/merchants/' . $merchant->getId()?->toRfc4122() . '/loyalty-programs', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->createJwtFor($superAdmin),
        ]);

        self::assertResponseStatusCodeSame(200);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame($merchant->getId()?->toRfc4122(), $payload['merchant']['id']);
        self::assertSame($merchant->getId()?->toRfc4122(), $payload['merchant']['merchant_ref']);
        self::assertSame($merchant->getId()?->toRfc4122(), $payload['merchant']['customer_signup_qr_value']);
        self::assertSame(2, $payload['total']);
        self::assertCount(2, $payload['items']);
        self::assertSame('Coffee Program', $payload['items'][0]['name']);
        self::assertSame('STAMP', $payload['items'][0]['type']);
        self::assertSame('Points Program', $payload['items'][1]['name']);
        self::assertSame('POINTS', $payload['items'][1]['type']);
    }

    public function testSuperAdminCanReadMerchantGoogleReviewModule(): void
    {
        $client = static::createClient();

        $superAdmin = $this->createUser('google-review-reader', ['ROLE_USER', 'ROLE_SUPER_ADMIN']);

        $merchantUser = $this->createUser('google-review-target', ['ROLE_USER', 'ROLE_MERCHANT']);
        $merchant = $this->createMerchant($merchantUser, 'Review Shop', 'Lille');
        $this->createGoogleReviewModule($merchant, true, 'https://g.page/r/review-shop/review');

        $client->request('GET', '/api/super-admin/merchants/' . $merchant->getId()?->toRfc4122() . '/google-review-module', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->createJwtFor($superAdmin),
        ]);

        self::assertResponseStatusCodeSame(200);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame($merchant->getId()?->toRfc4122(), $payload['merchant_id']);
        self::assertSame('Review Shop', $payload['merchant_name']);
        self::assertTrue($payload['is_enabled']);
        self::assertSame('https://g.page/r/review-shop/review', $payload['google_review_url']);
        self::assertArrayHasKey('is_configuration_complete', $payload);
    }

    public function testSuperAdminGets404WhenMerchantGoogleReviewModuleIsMissing(): void
    {
        $client = static::createClient();

        $superAdmin = $this->createUser('google-review-missing-admin', ['ROLE_USER', 'ROLE_SUPER_ADMIN']);

        $merchantUser = $this->createUser('google-review-missing-target', ['ROLE_USER', 'ROLE_MERCHANT']);
        $merchant = $this->createMerchant($merchantUser, 'No Review Shop', 'Nice');

        $client->request('GET', '/api/super-admin/merchants/' . $merchant->getId()?->toRfc4122() . '/google-review-module', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->createJwtFor($superAdmin),
        ]);

        self::assertResponseStatusCodeSame(404);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('google_review_module_not_found', $payload['error']);
    }

    private function createUser(string $suffix, array $roles): User
    {
        $user = new User();
        $user->setEmail(sprintf('super-admin-%s-%s@example.com', $suffix, bin2hex(random_bytes(4))));
        $user->setName('User ' . $suffix);
        $user->setRoles($roles);

        $em = $this->getEntityManager();
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function createMerchant(User $user, string $companyName, string $city): Merchant
    {
        $merchant = new Merchant();
        $merchant->setCompanyName($companyName);
        $merchant->setEmail($user->getEmail());
        $merchant->setPostalCode('75000');
        $merchant->setCity($city);
        $merchant->setAcceptedTerms(true);
        $merchant->setAcceptedTermsVersion('2026-04-15');
        $merchant->setAcceptedTermsAcceptedAt(new \DateTime('2026-04-15T10:15:00Z'));
        $merchant->setSubscriptionStatus('trial');
        $merchant->setTrialEndsAt(new \DateTime('2026-05-15T10:15:00Z'));
        $merchant->setUser($user);

        $em = $this->getEntityManager();
        $em->persist($merchant);
        $em->flush();

        return $merchant;
    }

    private function createLoyaltyProgram(
        Merchant $merchant,
        string $name,
        LoyaltyProgramType $type,
        ?int $pointsPerEuro,
        ?int $pointsTarget,
        ?int $stampTarget,
        ?string $rewardDescription,
    ): LoyaltyProgram {
        $program = new LoyaltyProgram();
        $program->setMerchant($merchant);
        $program->setName($name);
        $program->setDescription(null);
        $program->setType($type);
        $program->setPointsPerEuro($pointsPerEuro);
        $program->setPointsTarget($pointsTarget);
        $program->setStampTarget($stampTarget);
        $program->setRewardDescription($rewardDescription);
        $program->setIsActive(true);

        $em = $this->getEntityManager();
        $em->persist($program);
        $em->flush();

        return $program;
    }

    private function createGoogleReviewModule(Merchant $merchant, bool $enabled, ?string $url): MerchantGoogleReviewModule
    {
        $module = new MerchantGoogleReviewModule();
        $module->setMerchant($merchant);
        $module->setIsEnabled($enabled);
        $module->setDisplayName('Avis Google');
        $module->setGoogleReviewUrl($url);
        $module->setShowInCustomerDashboard(true);
        $module->setShowQrCode(true);
        $module->setRewardOptions([
            [
                'id' => 'reward-' . bin2hex(random_bytes(4)),
                'label' => 'Cafe offert',
                'description' => null,
                'active' => true,
                'order' => 1,
            ],
        ]);

        $em = $this->getEntityManager();
        $em->persist($module);
        $em->flush();

        return $module;
    }

    private function createJwtFor(User $user): string
    {
        /** @var JWTTokenManagerInterface $jwtManager */
        $jwtManager = static::getContainer()->get(JWTTokenManagerInterface::class);

        return $jwtManager->create($user);
    }

    private function getEntityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        return $entityManager;
    }

    private function isMerchantUserIdNullable(): bool
    {
        $connection = $this->getEntityManager()->getConnection();
        $databaseName = (string) $connection->fetchOne('SELECT DATABASE()');

        $isNullable = $connection->fetchOne(
            'SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :tableName AND COLUMN_NAME = :columnName',
            [
                'schema' => $databaseName,
                'tableName' => 'merchant',
                'columnName' => 'user_id',
            ],
        );

        return strtoupper((string) $isNullable) === 'YES';
    }
}
