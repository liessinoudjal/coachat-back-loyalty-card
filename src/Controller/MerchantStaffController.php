<?php

namespace App\Controller;

use App\Entity\Customer;
use App\Entity\Merchant;
use App\Entity\User;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class MerchantStaffController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly NotificationService $notificationService,
    ) {
    }

    #[Route('/api/merchants/me/staff', name: 'merchant_staff_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $merchant = $this->resolveOwnedMerchant();
        if (!$merchant instanceof Merchant) {
            return new JsonResponse(['error' => 'Merchant not found'], 404);
        }

        $staffCustomers = $this->entityManager->getRepository(Customer::class)->findStaffByMerchant($merchant);

        return new JsonResponse(array_map(
            fn (Customer $customer): array => $this->formatStaffCustomer($customer, $merchant),
            $staffCustomers,
        ));
    }

    #[Route('/api/merchants/me/staff/{customerId}', name: 'merchant_staff_assign', methods: ['POST'], requirements: ['customerId' => '\\d+'])]
    public function assign(int $customerId): JsonResponse
    {
        $merchant = $this->resolveMerchantAdminActor();

        if (!$merchant instanceof Merchant) {
            return new JsonResponse(['error' => 'Forbidden'], 403);
        }

        $customer = $this->entityManager->getRepository(Customer::class)->findByIdAndMerchant($customerId, $merchant);
        if (!$customer instanceof Customer) {
            return new JsonResponse(['error' => 'Customer not found'], 404);
        }

        $customerUser = $customer->getUser();
        if ($customerUser === null) {
            return new JsonResponse([
                'error' => 'customer_user_required',
                'message' => 'Only customers linked to a user account can become equipier.',
            ], 409);
        }

        $existingStaffMerchant = $customer->getStaffMerchant();
        if ($existingStaffMerchant instanceof Merchant && $existingStaffMerchant !== $merchant) {
            return new JsonResponse([
                'error' => 'customer_already_staff_for_other_merchant',
                'current_staff_merchant_id' => $existingStaffMerchant->getId()?->toRfc4122(),
            ], 409);
        }

        $customer->setStaffMerchant($merchant);
        $customer->setStaffAssignedAt(new \DateTimeImmutable());

        $roles = $customerUser->getRoles();
        if (!in_array('ROLE_CUSTOMER', $roles, true)) {
            $roles[] = 'ROLE_CUSTOMER';
        }
        if (!in_array('ROLE_EQUIPIER', $roles, true)) {
            $roles[] = 'ROLE_EQUIPIER';
        }
        $customerUser->setRoles(array_values(array_unique($roles)));

        $this->entityManager->flush();
        $this->notificationService->notifyEquipierAssigned($customer, $merchant);

        return new JsonResponse($this->formatStaffCustomer($customer, $merchant));
    }

    #[Route('/api/merchants/me/staff/{customerId}', name: 'merchant_staff_unassign', methods: ['DELETE'], requirements: ['customerId' => '\\d+'])]
    public function unassign(int $customerId): JsonResponse
    {
        $merchant = $this->resolveMerchantAdminActor();

        if (!$merchant instanceof Merchant) {
            return new JsonResponse(['error' => 'Forbidden'], 403);
        }

        $customer = $this->entityManager->getRepository(Customer::class)->findByIdAndMerchant($customerId, $merchant);
        if (!$customer instanceof Customer || $customer->getStaffMerchant() !== $merchant) {
            return new JsonResponse(['error' => 'Staff customer not found'], 404);
        }

        $customer->setStaffMerchant(null);
        $customer->setStaffAssignedAt(null);

        $customerUser = $customer->getUser();
        if ($customerUser !== null) {
            if ($customerUser === $merchant->getUser()) {
                return new JsonResponse([
                    'error' => 'owner_role_change_forbidden',
                    'message' => 'Merchant owner cannot be changed from staff role routes.',
                ], 409);
            }

            if (in_array('ROLE_MERCHANT', $customerUser->getRoles(), true)
                && !$this->canRemoveMerchantRole($merchant, $customerUser)
            ) {
                return new JsonResponse([
                    'error' => 'merchant_admin_minimum_required',
                    'message' => 'At least one merchant admin must remain.',
                ], 409);
            }

            $roles = array_values(array_filter(
                $customerUser->getRoles(),
                static fn (string $role): bool => $role !== 'ROLE_EQUIPIER' && $role !== 'ROLE_MERCHANT',
            ));
            $customerUser->setRoles(array_values(array_unique($roles)));
        }

        $this->entityManager->flush();
        $this->notificationService->notifyEquipierRemoved($customer, $merchant);

        return new JsonResponse(['success' => true]);
    }

    #[Route('/api/merchants/me/staff/merchant-admins', name: 'merchant_staff_merchant_admins_list', methods: ['GET'])]
    public function listMerchantAdmins(): JsonResponse
    {
        $user = $this->getUser();
        $merchant = $this->resolveMerchantAdminActor();

        if (!$merchant instanceof Merchant) {
            return new JsonResponse(['error' => 'Forbidden'], 403);
        }

        $staffCustomers = $this->entityManager->getRepository(Customer::class)->findStaffByMerchant($merchant);
        $adminCustomers = array_filter(
            $staffCustomers,
            fn (Customer $customer): bool => $this->isMerchantAdminStaffCustomer($customer, $merchant)
                && $customer->getUser() !== $user,
        );

        return new JsonResponse(array_values(array_map(
            fn (Customer $customer): array => $this->formatStaffCustomer($customer, $merchant),
            $adminCustomers,
        )));
    }

    #[Route('/api/merchants/me/staff/{customerId}/merchant-role', name: 'merchant_staff_promote_to_merchant', methods: ['POST'], requirements: ['customerId' => '\\d+'])]
    public function promoteToMerchantRole(int $customerId): JsonResponse
    {
        $merchant = $this->resolveMerchantAdminActor();

        if (!$merchant instanceof Merchant) {
            return new JsonResponse(['error' => 'Forbidden'], 403);
        }

        $customer = $this->entityManager->getRepository(Customer::class)->findByIdAndMerchant($customerId, $merchant);
        if (!$customer instanceof Customer) {
            return new JsonResponse(['error' => 'Customer not found'], 404);
        }

        if ($customer->getStaffMerchant() !== $merchant) {
            return new JsonResponse([
                'error' => 'customer_not_equipier_for_merchant',
                'message' => 'Only equipier staff can be promoted to merchant role.',
            ], 409);
        }

        $customerUser = $customer->getUser();
        if ($customerUser === null) {
            return new JsonResponse([
                'error' => 'customer_user_required',
                'message' => 'Only customers linked to a user account can be promoted.',
            ], 409);
        }

        if (!in_array('ROLE_EQUIPIER', $customerUser->getRoles(), true)) {
            return new JsonResponse([
                'error' => 'customer_not_equipier_role',
                'message' => 'Only equipier users can be promoted to merchant role.',
            ], 409);
        }

        $this->removeUserRole($customerUser, 'ROLE_EQUIPIER');
        $this->ensureUserRole($customerUser, 'ROLE_MERCHANT');
        $this->ensureUserRole($customerUser, 'ROLE_CUSTOMER');

        $this->entityManager->flush();

        return new JsonResponse($this->formatStaffCustomer($customer, $merchant));
    }

    #[Route('/api/merchants/me/staff/{customerId}/equipier-role', name: 'merchant_staff_demote_to_equipier', methods: ['POST'], requirements: ['customerId' => '\\d+'])]
    public function demoteToEquipierRole(int $customerId): JsonResponse
    {
        $user = $this->getUser();
        $merchant = $this->resolveMerchantAdminActor();

        if (!$merchant instanceof Merchant) {
            return new JsonResponse(['error' => 'Forbidden'], 403);
        }

        $customer = $this->entityManager->getRepository(Customer::class)->findByIdAndMerchant($customerId, $merchant);
        if (!$customer instanceof Customer || $customer->getStaffMerchant() !== $merchant) {
            return new JsonResponse(['error' => 'Staff customer not found'], 404);
        }

        $customerUser = $customer->getUser();
        if ($customerUser === null) {
            return new JsonResponse([
                'error' => 'customer_user_required',
                'message' => 'Only customers linked to a user account can be updated.',
            ], 409);
        }

        if ($customerUser === $user) {
            return new JsonResponse([
                'error' => 'merchant_admin_self_downgrade_forbidden',
                'message' => 'You cannot downgrade your own merchant role.',
            ], 409);
        }

        if (!in_array('ROLE_MERCHANT', $customerUser->getRoles(), true)) {
            return new JsonResponse([
                'error' => 'customer_not_merchant_admin',
                'message' => 'Customer is not a merchant admin.',
            ], 409);
        }

        if ($customerUser === $merchant->getUser()) {
            return new JsonResponse([
                'error' => 'owner_role_change_forbidden',
                'message' => 'Merchant owner cannot be changed from staff role routes.',
            ], 409);
        }

        if (!$this->canRemoveMerchantRole($merchant, $customerUser)) {
            return new JsonResponse([
                'error' => 'merchant_admin_minimum_required',
                'message' => 'At least one merchant admin must remain.',
            ], 409);
        }

        $this->removeUserRole($customerUser, 'ROLE_MERCHANT');
        $this->ensureUserRole($customerUser, 'ROLE_EQUIPIER');
        $this->ensureUserRole($customerUser, 'ROLE_CUSTOMER');

        $this->entityManager->flush();

        return new JsonResponse($this->formatStaffCustomer($customer, $merchant));
    }

    #[Route('/api/merchants/me/staff/{customerId}/transfer-ownership', name: 'merchant_staff_transfer_ownership', methods: ['POST'], requirements: ['customerId' => '\\d+'])]
    public function transferOwnership(int $customerId): JsonResponse
    {
        $user = $this->getUser();
        $merchant = $this->resolveMerchantAdminActor();
        if (!$user instanceof User || !$merchant instanceof Merchant) {
            return new JsonResponse(['error' => 'Forbidden'], 403);
        }

        if ($merchant->getUser() !== $user) {
            return new JsonResponse([
                'error' => 'owner_only_action',
                'message' => 'Only the current merchant owner can transfer ownership.',
            ], 403);
        }

        $customer = $this->entityManager->getRepository(Customer::class)->findByIdAndMerchant($customerId, $merchant);
        if (!$customer instanceof Customer || $customer->getStaffMerchant() !== $merchant) {
            return new JsonResponse(['error' => 'Staff customer not found'], 404);
        }

        $newOwnerUser = $customer->getUser();
        if (!$newOwnerUser instanceof User) {
            return new JsonResponse([
                'error' => 'customer_user_required',
                'message' => 'Only customers linked to a user account can become owner.',
            ], 409);
        }

        if (!in_array('ROLE_MERCHANT', $newOwnerUser->getRoles(), true)) {
            return new JsonResponse([
                'error' => 'customer_not_merchant_admin',
                'message' => 'Target customer must already be merchant admin.',
            ], 409);
        }

        if ($newOwnerUser === $user) {
            return new JsonResponse([
                'error' => 'owner_already_current',
                'message' => 'User is already the current owner.',
            ], 409);
        }

        if ($newOwnerUser->getMerchant() !== null && $newOwnerUser->getMerchant() !== $merchant) {
            return new JsonResponse([
                'error' => 'target_already_owner_for_other_merchant',
                'message' => 'Target user already owns another merchant account.',
            ], 409);
        }

        $merchant->setUser($newOwnerUser);
        $this->ensureUserRole($newOwnerUser, 'ROLE_MERCHANT');
        $this->ensureUserRole($newOwnerUser, 'ROLE_CUSTOMER');

        // Owner is no longer part of staff list; ownership is now represented by direct merchant relation.
        $customer->setStaffMerchant(null);
        $customer->setStaffAssignedAt(null);

        if (!in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            $this->removeUserRole($user, 'ROLE_MERCHANT');
        }

        $this->entityManager->flush();

        return new JsonResponse([
            'success' => true,
            'merchant_id' => $merchant->getId()?->toRfc4122(),
            'previous_owner_user_id' => $user->getId(),
            'new_owner' => $this->formatStaffCustomer($customer, $merchant),
        ]);
    }

    private function resolveOwnedMerchant(): ?Merchant
    {
        $user = $this->getUser();
        if ($user === null) {
            return null;
        }

        // If merchant owner
        if ($user->getMerchant() !== null) {
            return $user->getMerchant();
        }

        // If equipier (customer with staff assignment)
        $customer = $user->getCustomer();
        if ($customer !== null) {
            return $customer->getStaffMerchant();
        }

        return null;
    }

    private function resolveMerchantAdminActor(): ?Merchant
    {
        $user = $this->getUser();
        if ($user === null || !in_array('ROLE_MERCHANT', $user->getRoles(), true)) {
            return null;
        }

        $merchant = $user->getMerchant();
        if ($merchant instanceof Merchant) {
            return $merchant;
        }

        return $user->getCustomer()?->getStaffMerchant();
    }

    private function formatStaffCustomer(Customer $customer, Merchant $merchant): array
    {
        $staffMerchant = $customer->getStaffMerchant();
        $customerUser = $customer->getUser();
        $roles = $customerUser?->getRoles() ?? ['ROLE_CUSTOMER'];
        $ownerUser = $merchant->getUser();

        return [
            'id' => $customer->getId(),
            'name' => $customer->getName(),
            'email' => $customer->getEmail(),
            'phone' => $customer->getPhone(),
            'is_equipier' => $staffMerchant !== null,
            'is_merchant_admin' => in_array('ROLE_MERCHANT', $roles, true),
            'is_owner' => $customerUser !== null && $ownerUser === $customerUser,
            'owner_user_id' => $ownerUser?->getId(),
            'equipier_merchant_id' => $staffMerchant?->getId()?->toRfc4122(),
            'equipier_assigned_at' => $customer->getStaffAssignedAt()?->format(DATE_ATOM),
            'roles' => $roles,
        ];
    }

    private function ensureUserRole(User $user, string $role): void
    {
        $roles = $user->getRoles();
        if (!in_array($role, $roles, true)) {
            $roles[] = $role;
            $user->setRoles(array_values(array_unique($roles)));
        }
    }

    private function removeUserRole(User $user, string $role): void
    {
        $roles = array_values(array_filter(
            $user->getRoles(),
            static fn (string $currentRole): bool => $currentRole !== $role,
        ));

        $user->setRoles(array_values(array_unique($roles)));
    }

    private function canRemoveMerchantRole(Merchant $merchant, User $userToRemove): bool
    {
        return $this->countMerchantAdmins($merchant, $userToRemove) >= 1;
    }

    private function countMerchantAdmins(Merchant $merchant, ?User $excludedUser = null): int
    {
        $count = 0;
        $owner = $merchant->getUser();
        if ($owner !== null && $owner !== $excludedUser && in_array('ROLE_MERCHANT', $owner->getRoles(), true)) {
            ++$count;
        }

        $staffCustomers = $this->entityManager->getRepository(Customer::class)->findStaffByMerchant($merchant);
        foreach ($staffCustomers as $customer) {
            $staffUser = $customer->getUser();
            if ($staffUser === null || $staffUser === $excludedUser || $staffUser === $owner) {
                continue;
            }

            if (in_array('ROLE_MERCHANT', $staffUser->getRoles(), true)) {
                ++$count;
            }
        }

        return $count;
    }

    private function isMerchantAdminStaffCustomer(Customer $customer, Merchant $merchant): bool
    {
        if ($customer->getStaffMerchant() !== $merchant) {
            return false;
        }

        $customerUser = $customer->getUser();

        return $customerUser !== null && in_array('ROLE_MERCHANT', $customerUser->getRoles(), true);
    }
}
