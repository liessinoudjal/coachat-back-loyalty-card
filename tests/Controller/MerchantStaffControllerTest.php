<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Customer;
use App\Entity\Merchant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MerchantStaffControllerTest extends WebTestCase
{
    public function testOwnerCanAssignCustomerAsEquipier(): void
    {
        $client = static::createClient();

        $owner = $this->createUser('staff-owner-assign', ['ROLE_USER', 'ROLE_MERCHANT']);
        $merchant = $this->createMerchant($owner, 'Staff Assign Merchant');

        $staffUser = $this->createUser('staff-target-assign', ['ROLE_USER', 'ROLE_CUSTOMER']);
        $customer = $this->createCustomer('Target Assign', 'staff-target-assign@example.com', $merchant, $staffUser);

        $client->request('POST', '/api/merchants/me/staff/' . $customer->getId(), server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->createJwtFor($owner),
        ]);

        self::assertResponseStatusCodeSame(200);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($payload['is_equipier']);
        self::assertContains('ROLE_EQUIPIER', $payload['roles']);
        self::assertContains('ROLE_CUSTOMER', $payload['roles']);
        self::assertFalse($payload['is_owner']);
        self::assertSame($owner->getId(), $payload['owner_user_id']);
    }

    public function testAssignReturns409WhenCustomerHasNoUser(): void
    {
        $client = static::createClient();

        $owner = $this->createUser('staff-owner-no-user', ['ROLE_USER', 'ROLE_MERCHANT']);
        $merchant = $this->createMerchant($owner, 'No User Merchant');
        $customer = $this->createCustomer('No User Customer', 'staff-no-user@example.com', $merchant);

        $client->request('POST', '/api/merchants/me/staff/' . $customer->getId(), server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->createJwtFor($owner),
        ]);

        self::assertResponseStatusCodeSame(409);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('customer_user_required', $payload['error']);
    }

    public function testPromoteRequiresExistingEquipierStaff(): void
    {
        $client = static::createClient();

        $owner = $this->createUser('staff-owner-promote-check', ['ROLE_USER', 'ROLE_MERCHANT']);
        $merchant = $this->createMerchant($owner, 'Promote Check Merchant');

        $candidateUser = $this->createUser('staff-candidate-promote-check', ['ROLE_USER', 'ROLE_CUSTOMER']);
        $candidate = $this->createCustomer('Candidate', 'staff-candidate-check@example.com', $merchant, $candidateUser);

        $client->request('POST', '/api/merchants/me/staff/' . $candidate->getId() . '/merchant-role', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->createJwtFor($owner),
        ]);

        self::assertResponseStatusCodeSame(409);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('customer_not_equipier_for_merchant', $payload['error']);
    }

    public function testPromoteAndDemoteMerchantRoleFlow(): void
    {
        $client = static::createClient();

        $owner = $this->createUser('staff-owner-promote-demote', ['ROLE_USER', 'ROLE_MERCHANT']);
        $merchant = $this->createMerchant($owner, 'Promote Demote Merchant');

        $staffUser = $this->createUser('staff-promote-demote-target', ['ROLE_USER', 'ROLE_CUSTOMER']);
        $customer = $this->createCustomer('Promote Demote Target', 'staff-promote-demote@example.com', $merchant, $staffUser);

        $client->request('POST', '/api/merchants/me/staff/' . $customer->getId(), server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->createJwtFor($owner),
        ]);
        self::assertResponseStatusCodeSame(200);

        $client->request('POST', '/api/merchants/me/staff/' . $customer->getId() . '/merchant-role', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->createJwtFor($owner),
        ]);

        self::assertResponseStatusCodeSame(200);
        $promotePayload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($promotePayload['is_merchant_admin']);
        self::assertContains('ROLE_MERCHANT', $promotePayload['roles']);
        self::assertNotContains('ROLE_EQUIPIER', $promotePayload['roles']);

        $client->request('POST', '/api/merchants/me/staff/' . $customer->getId() . '/equipier-role', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->createJwtFor($owner),
        ]);

        self::assertResponseStatusCodeSame(200);
        $demotePayload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($demotePayload['is_merchant_admin']);
        self::assertContains('ROLE_EQUIPIER', $demotePayload['roles']);
        self::assertNotContains('ROLE_MERCHANT', $demotePayload['roles']);
    }

    public function testListMerchantAdminsExcludesCurrentUser(): void
    {
        $client = static::createClient();

        $owner = $this->createUser('staff-owner-admin-list', ['ROLE_USER', 'ROLE_MERCHANT']);
        $merchant = $this->createMerchant($owner, 'Admin List Merchant');

        $adminUserA = $this->createUser('staff-admin-a', ['ROLE_USER', 'ROLE_CUSTOMER', 'ROLE_MERCHANT']);
        $adminCustomerA = $this->createCustomer('Admin A', 'staff-admin-a@example.com', $merchant, $adminUserA);
        $adminCustomerA->setStaffMerchant($merchant);
        $adminCustomerA->setStaffAssignedAt(new \DateTimeImmutable());

        $adminUserB = $this->createUser('staff-admin-b', ['ROLE_USER', 'ROLE_CUSTOMER', 'ROLE_MERCHANT']);
        $adminCustomerB = $this->createCustomer('Admin B', 'staff-admin-b@example.com', $merchant, $adminUserB);
        $adminCustomerB->setStaffMerchant($merchant);
        $adminCustomerB->setStaffAssignedAt(new \DateTimeImmutable());

        $this->getEntityManager()->flush();

        $client->request('GET', '/api/merchants/me/staff/merchant-admins', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->createJwtFor($adminUserA),
        ]);

        self::assertResponseStatusCodeSame(200);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(1, $payload);
        self::assertSame($adminCustomerB->getId(), $payload[0]['id']);
    }

    public function testCannotDowngradeOwnerViaStaffRoute(): void
    {
        $client = static::createClient();

        $owner = $this->createUser('staff-owner-downgrade-guard', ['ROLE_USER', 'ROLE_MERCHANT']);
        $merchant = $this->createMerchant($owner, 'Owner Guard Merchant');

        $adminUser = $this->createUser('staff-admin-downgrade-guard', ['ROLE_USER', 'ROLE_CUSTOMER', 'ROLE_MERCHANT']);
        $adminCustomer = $this->createCustomer('Admin Guard', 'staff-admin-guard@example.com', $merchant, $adminUser);
        $adminCustomer->setStaffMerchant($merchant);
        $adminCustomer->setStaffAssignedAt(new \DateTimeImmutable());

        // Create a customer linked to owner user to emulate an invalid owner-as-staff state and ensure guard is enforced.
        $ownerCustomer = $this->createCustomer('Owner Linked', 'staff-owner-linked@example.com', $merchant, $owner);
        $ownerCustomer->setStaffMerchant($merchant);
        $ownerCustomer->setStaffAssignedAt(new \DateTimeImmutable());

        $this->getEntityManager()->flush();

        $client->request('POST', '/api/merchants/me/staff/' . $ownerCustomer->getId() . '/equipier-role', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->createJwtFor($adminUser),
        ]);

        self::assertResponseStatusCodeSame(409);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('owner_role_change_forbidden', $payload['error']);
    }

    public function testTransferOwnershipIsOwnerOnly(): void
    {
        $client = static::createClient();

        $owner = $this->createUser('staff-owner-transfer-forbidden', ['ROLE_USER', 'ROLE_MERCHANT']);
        $merchant = $this->createMerchant($owner, 'Transfer Owner Merchant');

        $adminUser = $this->createUser('staff-admin-transfer-forbidden', ['ROLE_USER', 'ROLE_CUSTOMER', 'ROLE_MERCHANT']);
        $adminCustomer = $this->createCustomer('Admin Transfer', 'staff-admin-transfer-forbidden@example.com', $merchant, $adminUser);
        $adminCustomer->setStaffMerchant($merchant);
        $adminCustomer->setStaffAssignedAt(new \DateTimeImmutable());

        $otherAdminUser = $this->createUser('staff-other-admin-transfer-forbidden', ['ROLE_USER', 'ROLE_CUSTOMER', 'ROLE_MERCHANT']);
        $otherAdminCustomer = $this->createCustomer('Other Admin', 'staff-other-admin-transfer-forbidden@example.com', $merchant, $otherAdminUser);
        $otherAdminCustomer->setStaffMerchant($merchant);
        $otherAdminCustomer->setStaffAssignedAt(new \DateTimeImmutable());

        $this->getEntityManager()->flush();

        $client->request('POST', '/api/merchants/me/staff/' . $otherAdminCustomer->getId() . '/transfer-ownership', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->createJwtFor($adminUser),
        ]);

        self::assertResponseStatusCodeSame(403);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('owner_only_action', $payload['error']);
    }

    public function testTransferOwnershipUpdatesMerchantOwnerAndFormerOwnerRole(): void
    {
        $client = static::createClient();

        $owner = $this->createUser('staff-owner-transfer-success', ['ROLE_USER', 'ROLE_MERCHANT']);
        $merchant = $this->createMerchant($owner, 'Transfer Success Merchant');

        $newOwnerUser = $this->createUser('staff-new-owner-transfer-success', ['ROLE_USER', 'ROLE_CUSTOMER', 'ROLE_MERCHANT']);
        $newOwnerCustomer = $this->createCustomer('New Owner', 'staff-new-owner-transfer-success@example.com', $merchant, $newOwnerUser);
        $newOwnerCustomer->setStaffMerchant($merchant);
        $newOwnerCustomer->setStaffAssignedAt(new \DateTimeImmutable());

        $this->getEntityManager()->flush();

        $client->request('POST', '/api/merchants/me/staff/' . $newOwnerCustomer->getId() . '/transfer-ownership', server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->createJwtFor($owner),
        ]);

        self::assertResponseStatusCodeSame(200);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($payload['success']);
        self::assertSame($owner->getId(), $payload['previous_owner_user_id']);
        self::assertTrue($payload['new_owner']['is_owner']);
        self::assertFalse($payload['new_owner']['is_equipier']);

        $em = $this->getEntityManager();
        $em->clear();

        /** @var Merchant $merchantReloaded */
        $merchantReloaded = $em->getRepository(Merchant::class)->find($merchant->getId());
        /** @var User $oldOwnerReloaded */
        $oldOwnerReloaded = $em->getRepository(User::class)->find($owner->getId());
        /** @var Customer $newOwnerCustomerReloaded */
        $newOwnerCustomerReloaded = $em->getRepository(Customer::class)->find($newOwnerCustomer->getId());

        self::assertNotNull($merchantReloaded);
        self::assertSame($newOwnerUser->getId(), $merchantReloaded->getUser()?->getId());
        self::assertNotContains('ROLE_MERCHANT', $oldOwnerReloaded->getRoles());
        self::assertNull($newOwnerCustomerReloaded->getStaffMerchant());
    }

    private function createUser(string $suffix, array $roles = ['ROLE_USER']): User
    {
        $user = new User();
        $user->setEmail(sprintf('merchant-staff-test-%s-%s@example.com', $suffix, bin2hex(random_bytes(3))));
        $user->setName('Merchant Staff Test ' . $suffix);
        $user->setRoles($roles);
        $user->setPassword('test-password');

        $em = $this->getEntityManager();
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function createMerchant(User $user, string $companyName): Merchant
    {
        $merchant = new Merchant();
        $merchant->setCompanyName($companyName);
        $merchant->setEmail($user->getEmail());
        $merchant->setSubscriptionStatus('trial');
        $merchant->setTrialEndsAt((new \DateTime())->modify('+30 days'));
        $merchant->setUser($user);

        $em = $this->getEntityManager();
        $em->persist($merchant);
        $em->flush();

        return $merchant;
    }

    private function createCustomer(string $name, string $email, ?Merchant $merchant = null, ?User $user = null): Customer
    {
        $customer = new Customer();
        $customer->setName($name);
        $customer->setEmail(sprintf('%s-%s', $email, bin2hex(random_bytes(2))));

        if ($merchant instanceof Merchant) {
            $customer->setMerchant($merchant);
            $customer->addMerchant($merchant);
        }

        if ($user instanceof User) {
            $customer->setUser($user);
        }

        $em = $this->getEntityManager();
        $em->persist($customer);
        $em->flush();

        return $customer;
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
