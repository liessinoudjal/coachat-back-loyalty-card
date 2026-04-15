<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\LoyaltyCard;
use App\Entity\LoyaltyProgram;
use App\Entity\Merchant;
use App\Entity\User;
use App\Enum\LoyaltyProgramType;
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
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(201);

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Cafe du Centre', $payload['company_name']);
        self::assertSame('01 23 45 67 89', $payload['phone']);
        self::assertSame('123 rue de la Paix, 75000 Paris', $payload['address']);
        self::assertSame('75000', $payload['postal_code']);
        self::assertSame('Paris', $payload['city']);
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
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(200);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('06 12 34 56 78', $payload['phone']);
        self::assertSame('42 avenue Victor Hugo', $payload['address']);
        self::assertSame('69002', $payload['postal_code']);
        self::assertSame('Lyon', $payload['city']);
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
        self::assertArrayHasKey('logo_url', $payload);
        self::assertSame('01 11 22 33 44', $payload['phone']);
        self::assertSame('10 rue Profile', $payload['address']);
        self::assertSame('33000', $payload['postal_code']);
        self::assertSame('Bordeaux', $payload['city']);
        self::assertStringStartsWith('data:image/webp;base64,', $payload['logo_url']);
    }

    public function testPublicByTokenIncludesMerchantLogoAndProfileFields(): void
    {
        $client = static::createClient();

        $user = $this->createUser('public-by-token');
        $merchant = $this->createMerchant(
            $user,
            'Public Shop',
            '01 99 88 77 66',
            '88 rue publique',
            'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQAAAQABAAD/2wCEAAkGBxISEhUTEhIVFRUVFRUVFRUVFRUVFRUWFxUWFhUVFRUYHSggGBolHRUVITEhJSkrLi4uFx8zODMsNygtLisBCgoKDg0OGhAQGi0fHR0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLf/AABEIAAEAAQMBIgACEQEDEQH/xAAXAAEBAQEAAAAAAAAAAAAAAAAAAQID/8QAFhEBAQEAAAAAAAAAAAAAAAAAAAER/8QAFQEBAQAAAAAAAAAAAAAAAAAAAgP/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCeAA==',
            '13001',
            'Marseille'
        );

        $program = new LoyaltyProgram();
        $program->setName('Stamp Program');
        $program->setType(LoyaltyProgramType::STAMP);
        $program->setStampTarget(10);
        $program->setMerchant($merchant);

        $card = new LoyaltyCard();
        $card->setCurrentValue(5);
        $card->setTargetValue(10);
        $card->setLoyaltyProgram($program);
        $card->setMerchant($merchant);

        $em = $this->getEntityManager();
        $em->persist($program);
        $em->persist($card);
        $em->flush();

        $client->request('GET', '/api/loyalty_cards/by-token/' . $card->getWalletToken());

        self::assertResponseStatusCodeSame(200);

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('merchant', $payload);
        self::assertSame('Public Shop', $payload['merchant']['company_name']);
        self::assertSame('01 99 88 77 66', $payload['merchant']['phone']);
        self::assertSame('88 rue publique', $payload['merchant']['address']);
        self::assertSame('13001', $payload['merchant']['postal_code']);
        self::assertSame('Marseille', $payload['merchant']['city']);
        self::assertStringStartsWith('data:image/jpeg;base64,', $payload['merchant']['logo_url']);
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
