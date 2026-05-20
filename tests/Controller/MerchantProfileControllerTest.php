<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Merchant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class MerchantProfileControllerTest extends WebTestCase
{
    public function testCreateMerchantWithOptionalFields(): void
    {
        $client = static::createClient();

        $user = $this->createUser('create-with-options');
        $token = $this->createJwtFor($user);

        $client->request(
            'POST',
            '/api/merchants',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            content: json_encode([
                'company_name' => 'Cafe du Centre',
                'phone' => '01 23 45 67 89',
                'address' => '123 rue de la Paix, 75000 Paris',
                'postal_code' => '75000',
                'city' => 'Paris',
                'accepted_terms' => true,
                'accepted_terms_version' => '2026-04-15',
                'accepted_terms_accepted_at' => '2026-04-15T10:15:00Z',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(201);

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Cafe du Centre', $payload['company_name']);
        self::assertSame('01 23 45 67 89', $payload['phone']);
        self::assertSame('123 rue de la Paix, 75000 Paris', $payload['address']);
        self::assertSame('75000', $payload['postal_code']);
        self::assertSame('Paris', $payload['city']);
        self::assertTrue($payload['accepted_terms']);
        self::assertSame('2026-04-15', $payload['accepted_terms_version']);
        self::assertSame('2026-04-15T10:15:00Z', $payload['accepted_terms_accepted_at']);
        self::assertNull($payload['logo_url']);
    }

    public function testCreateMerchantRequiresPostalCodeAndCity(): void
    {
        $client = static::createClient();

        $user = $this->createUser('create-without-options');
        $token = $this->createJwtFor($user);

        $client->request(
            'POST',
            '/api/merchants',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            content: json_encode([
                'company_name' => 'Only Name Shop',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(400);

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('postal_code required', $payload['error']);
    }

    public function testCreateMerchantRefusedWhenAcceptedTermsMissing(): void
    {
        $client = static::createClient();

        $user = $this->createUser('create-missing-accepted-terms');
        $token = $this->createJwtFor($user);

        $client->request(
            'POST',
            '/api/merchants',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            content: json_encode([
                'company_name' => 'Missing Terms Shop',
                'postal_code' => '75000',
                'city' => 'Paris',
                'accepted_terms_version' => '2026-04-15',
                'accepted_terms_accepted_at' => '2026-04-15T10:15:00Z',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(422);

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('accepted_terms must be true', $payload['error']);
    }

    public function testCreateMerchantRefusedWhenAcceptedTermsIsFalse(): void
    {
        $client = static::createClient();

        $user = $this->createUser('create-false-accepted-terms');
        $token = $this->createJwtFor($user);

        $client->request(
            'POST',
            '/api/merchants',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            content: json_encode([
                'company_name' => 'False Terms Shop',
                'postal_code' => '75000',
                'city' => 'Paris',
                'accepted_terms' => false,
                'accepted_terms_version' => '2026-04-15',
                'accepted_terms_accepted_at' => '2026-04-15T10:15:00Z',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(422);

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('accepted_terms must be true', $payload['error']);
    }

    public function testCreateMerchantRefusedWhenAcceptedTermsVersionMissing(): void
    {
        $client = static::createClient();

        $user = $this->createUser('create-missing-terms-version');
        $token = $this->createJwtFor($user);

        $client->request(
            'POST',
            '/api/merchants',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            content: json_encode([
                'company_name' => 'Missing Version Shop',
                'postal_code' => '75000',
                'city' => 'Paris',
                'accepted_terms' => true,
                'accepted_terms_accepted_at' => '2026-04-15T10:15:00Z',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(422);

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('accepted_terms_version required', $payload['error']);
    }

    public function testCreateMerchantRefusedWhenAcceptedTermsAcceptedAtInvalid(): void
    {
        $client = static::createClient();

        $user = $this->createUser('create-invalid-terms-date');
        $token = $this->createJwtFor($user);

        $client->request(
            'POST',
            '/api/merchants',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            content: json_encode([
                'company_name' => 'Invalid Date Shop',
                'postal_code' => '75000',
                'city' => 'Paris',
                'accepted_terms' => true,
                'accepted_terms_version' => '2026-04-15',
                'accepted_terms_accepted_at' => 'invalid-date',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(422);

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('accepted_terms_accepted_at must be a valid datetime', $payload['error']);
    }

    public function testCreateMerchantWithEmptyEmailFallsBackToOwnerEmail(): void
    {
        $client = static::createClient();

        $user = $this->createUser('create-empty-email');
        $token = $this->createJwtFor($user);

        $client->request(
            'POST',
            '/api/merchants',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            content: json_encode([
                'company_name' => 'Invalid Email Shop',
                'email' => '   ',
                'postal_code' => '75000',
                'city' => 'Paris',
                'accepted_terms' => true,
                'accepted_terms_version' => '2026-04-15',
                'accepted_terms_accepted_at' => '2026-04-15T10:15:00Z',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(201);

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($user->getEmail(), $payload['email']);
    }

    public function testPartialUpdateMerchantProfile(): void
    {
        $client = static::createClient();

        $user = $this->createUser('partial-update');
        $merchant = $this->createMerchant($user, 'Initial Shop');
        $token = $this->createJwtFor($user);

        $client->request(
            'PUT',
            '/api/merchants/' . $merchant->getId()->toRfc4122(),
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            content: json_encode(['phone' => '06 12 34 56 78'], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(200);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('06 12 34 56 78', $payload['phone']);
        self::assertNull($payload['address']);
        self::assertNull($payload['postal_code']);
        self::assertNull($payload['city']);
        self::assertTrue($payload['accepted_terms']);
        self::assertSame('2026-04-15', $payload['accepted_terms_version']);
        self::assertSame('2026-04-15T10:15:00Z', $payload['accepted_terms_accepted_at']);

        $client->request(
            'PUT',
            '/api/merchants/' . $merchant->getId()->toRfc4122(),
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            content: json_encode([
                'address' => '42 avenue Victor Hugo',
                'postal_code' => '69002',
                'city' => 'Lyon',
                'accepted_terms_version' => '2026-04-15',
                'accepted_terms_accepted_at' => '2026-05-01T12:00:00Z',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(200);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('06 12 34 56 78', $payload['phone']);
        self::assertIsString($payload['address']);
        self::assertStringContainsString('Victor Hugo', $payload['address']);
        self::assertSame('69002', $payload['postal_code']);
        self::assertSame('Lyon', $payload['city']);
        self::assertTrue($payload['accepted_terms']);
        self::assertSame('2026-04-15', $payload['accepted_terms_version']);
        self::assertSame('2026-05-01T12:00:00Z', $payload['accepted_terms_accepted_at']);
    }

    public function testMerchantUpdateIgnoresDifferentEmail(): void
    {
        $client = static::createClient();

        $user = $this->createUser('merchant-email-readonly-different');
        $merchant = $this->createMerchant($user, 'Readonly Email Shop');
        $token = $this->createJwtFor($user);
        $originalEmail = $merchant->getEmail();

        $client->request(
            'PUT',
            '/api/merchants/' . $merchant->getId()->toRfc4122(),
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            content: json_encode([
                'email' => 'changed@example.com',
                'phone' => '06 55 44 33 22',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(200);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($originalEmail, $payload['email']);
        self::assertSame('06 55 44 33 22', $payload['phone']);
    }

    public function testMerchantUpdateIgnoresIdenticalEmail(): void
    {
        $client = static::createClient();

        $user = $this->createUser('merchant-email-readonly-identical');
        $merchant = $this->createMerchant($user, 'Readonly Email Shop 2');
        $token = $this->createJwtFor($user);
        $originalEmail = $merchant->getEmail();

        $client->request(
            'PUT',
            '/api/merchants/' . $merchant->getId()->toRfc4122(),
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            content: json_encode([
                'email' => $originalEmail,
                'city' => 'Nantes',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(200);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($originalEmail, $payload['email']);
        self::assertSame('Nantes', $payload['city']);
    }

    public function testMerchantCannotSetAcceptedTermsToFalse(): void
    {
        $client = static::createClient();

        $user = $this->createUser('accepted-terms-false-update');
        $merchant = $this->createMerchant($user, 'Terms Shop');
        $token = $this->createJwtFor($user);

        $client->request(
            'PUT',
            '/api/merchants/' . $merchant->getId()->toRfc4122(),
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            content: json_encode(['accepted_terms' => false], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(422);

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('accepted_terms cannot be set to false', $payload['error']);
    }

    public function testMerchantCannotUpdateAnotherMerchantProfile(): void
    {
        $client = static::createClient();

        $ownerUser = $this->createUser('owner-merchant');
        $ownerMerchant = $this->createMerchant($ownerUser, 'Owner Shop');

        $otherUser = $this->createUser('other-merchant');
        $otherToken = $this->createJwtFor($otherUser);

        $client->request(
            'PUT',
            '/api/merchants/' . $ownerMerchant->getId()->toRfc4122(),
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $otherToken,
            ],
            content: json_encode(['phone' => '00 00 00 00 00'], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(404);
    }

    public function testUploadValidLogoBase64(): void
    {
        $client = static::createClient();

        $user = $this->createUser('upload-valid-logo');
        $merchant = $this->createMerchant($user, 'Logo Shop');
        $token = $this->createJwtFor($user);

        $logo = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8Xw8AAoMBgQqNn1gAAAAASUVORK5CYII=';

        $client->request(
            'POST',
            '/api/merchants/' . $merchant->getId()->toRfc4122() . '/logo',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            content: json_encode(['logo' => $logo], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(200);

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($logo, $payload['logo_url']);
    }

    public function testRejectLogoTooLarge(): void
    {
        $client = static::createClient();

        $user = $this->createUser('upload-large-logo');
        $merchant = $this->createMerchant($user, 'Large Logo Shop');
        $token = $this->createJwtFor($user);

        $tooLargeRaw = str_repeat('A', 5242881);
        $logo = 'data:image/png;base64,' . base64_encode($tooLargeRaw);

        $client->request(
            'POST',
            '/api/merchants/' . $merchant->getId()->toRfc4122() . '/logo',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            content: json_encode(['logo' => $logo], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(400);

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Fichier trop volumineux (max 5MB)', $payload['error']);
    }

    public function testRejectNonImageLogoFormat(): void
    {
        $client = static::createClient();

        $user = $this->createUser('upload-non-image-logo');
        $merchant = $this->createMerchant($user, 'Invalid Logo Shop');
        $token = $this->createJwtFor($user);

        $logo = 'data:text/plain;base64,' . base64_encode('not-an-image');

        $client->request(
            'POST',
            '/api/merchants/' . $merchant->getId()->toRfc4122() . '/logo',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            content: json_encode(['logo' => $logo], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(400);

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Format invalide', $payload['error']);
    }

    public function testGetMerchantsMeIncludesNewFields(): void
    {
        $client = static::createClient();

        $user = $this->createUser('merchants-me');
        $this->createMerchant(
            $user,
            'Profile Shop',
            '01 11 22 33 44',
            '10 rue Profile',
            'data:image/webp;base64,UklGRiQAAABXRUJQVlA4IBgAAAAQAgCdASoQAAkAAUAmJaQAA3AA/v89WAAAAA==',
            '33000',
            'Bordeaux'
        );
        $token = $this->createJwtFor($user);

        $client->request('GET', '/api/merchants/me', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);

        self::assertResponseStatusCodeSame(200);

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('phone', $payload);
        self::assertArrayHasKey('address', $payload);
        self::assertArrayHasKey('postal_code', $payload);
        self::assertArrayHasKey('city', $payload);
        self::assertArrayHasKey('accepted_terms', $payload);
        self::assertArrayHasKey('accepted_terms_version', $payload);
        self::assertArrayHasKey('accepted_terms_accepted_at', $payload);
        self::assertArrayHasKey('logo_url', $payload);
        self::assertSame('01 11 22 33 44', $payload['phone']);
        self::assertIsString($payload['address']);
        self::assertNotSame('', trim($payload['address']));
        self::assertSame('33000', $payload['postal_code']);
        self::assertSame('Bordeaux', $payload['city']);
        self::assertTrue($payload['accepted_terms']);
        self::assertSame('2026-04-15', $payload['accepted_terms_version']);
        self::assertSame('2026-04-15T10:15:00Z', $payload['accepted_terms_accepted_at']);
        self::assertStringStartsWith('data:image/webp;base64,', $payload['logo_url']);
    }

    private function createUser(string $suffix): User
    {
        $user = new User();
        $user->setEmail(sprintf('merchant-%s-%s@example.com', $suffix, bin2hex(random_bytes(4))));
        $user->setName('Merchant ' . $suffix);
        $user->setRoles(['ROLE_USER']);

        $em = $this->getEntityManager();
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function createMerchant(
        User $user,
        string $companyName,
        ?string $phone = null,
        ?string $address = null,
        ?string $logoUrl = null,
        ?string $postalCode = null,
        ?string $city = null
    ): Merchant {
        $merchant = new Merchant();
        $merchant->setCompanyName($companyName);
        $merchant->setEmail($user->getEmail());
        $merchant->setPhone($phone);
        $merchant->setAddress($address);
        $merchant->setLogoUrl($logoUrl);
        $merchant->setPostalCode($postalCode);
        $merchant->setCity($city);
        $merchant->setAcceptedTerms(true);
        $merchant->setAcceptedTermsVersion('2026-04-15');
        $merchant->setAcceptedTermsAcceptedAt(new \DateTime('2026-04-15T10:15:00Z'));
        $merchant->setSubscriptionStatus('trial');
        $merchant->setTrialEndsAt((new \DateTimeImmutable())->modify('+30 days'));
        $merchant->setUser($user);

        $em = $this->getEntityManager();
        $em->persist($merchant);
        $em->flush();

        return $merchant;
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
}
