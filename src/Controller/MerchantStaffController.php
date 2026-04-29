<?php

namespace App\Controller;

use App\Entity\Customer;
use App\Entity\Merchant;
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

        return new JsonResponse(array_map(fn (Customer $customer): array => $this->formatStaffCustomer($customer), $staffCustomers));
    }

    #[Route('/api/merchants/me/staff/{customerId}', name: 'merchant_staff_assign', methods: ['POST'])]
    public function assign(int $customerId): JsonResponse
    {
        $user = $this->getUser();
        $merchant = $user?->getMerchant();
        
        // Only merchant owner can assign, not equipier
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

        return new JsonResponse($this->formatStaffCustomer($customer));
    }

    #[Route('/api/merchants/me/staff/{customerId}', name: 'merchant_staff_unassign', methods: ['DELETE'])]
    public function unassign(int $customerId): JsonResponse
    {
        $user = $this->getUser();
        $merchant = $user?->getMerchant();
        
        // Only merchant owner can unassign, not equipier
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
            $roles = array_values(array_filter(
                $customerUser->getRoles(),
                static fn (string $role): bool => $role !== 'ROLE_EQUIPIER',
            ));
            $customerUser->setRoles(array_values(array_unique($roles)));
        }

        $this->entityManager->flush();
        $this->notificationService->notifyEquipierRemoved($customer, $merchant);

        return new JsonResponse(['success' => true]);
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

    private function formatStaffCustomer(Customer $customer): array
    {
        $staffMerchant = $customer->getStaffMerchant();

        return [
            'id' => $customer->getId(),
            'name' => $customer->getName(),
            'email' => $customer->getEmail(),
            'phone' => $customer->getPhone(),
            'is_equipier' => $staffMerchant !== null,
            'equipier_merchant_id' => $staffMerchant?->getId()?->toRfc4122(),
            'equipier_assigned_at' => $customer->getStaffAssignedAt()?->format(DATE_ATOM),
            'roles' => $customer->getUser()?->getRoles() ?? ['ROLE_CUSTOMER'],
        ];
    }
}
