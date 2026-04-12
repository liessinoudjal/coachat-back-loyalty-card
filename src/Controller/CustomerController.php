<?php

namespace App\Controller;

use App\Entity\Customer;
use App\Entity\LoyaltyCard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class CustomerController extends AbstractController
{
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    #[Route('/api/customers', name: 'create_customer', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $merchant = $user->getMerchant();
        if (!$merchant) {
            return new JsonResponse(['error' => 'Merchant not found for user'], 404);
        }

        $data = json_decode($request->getContent(), true);
        if (!isset($data['name']) || !isset($data['email'])) {
            return new JsonResponse(['error' => 'name and email required'], 400);
        }

        $customer = new Customer();
        $customer->setName($data['name']);
        $customer->setEmail($data['email']);
        $customer->setPhone($data['phone'] ?? null);
        $customer->setMerchant($merchant);

        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        return new JsonResponse([
            'id' => $customer->getId(),
            'name' => $customer->getName(),
            'email' => $customer->getEmail(),
            'phone' => $customer->getPhone(),
        ], 201);
    }

    #[Route('/api/customers', name: 'get_customers', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $merchant = $user->getMerchant();
        if (!$merchant) {
            return new JsonResponse(['error' => 'Merchant not found for user'], 404);
        }

        // Force merchant from JWT, ignore query parameter for security
        $customers = $this->entityManager->getRepository(Customer::class)->findByMerchant($merchant);

        return new JsonResponse(array_map(fn(Customer $c) => [
            'id' => $c->getId(),
            'name' => $c->getName(),
            'email' => $c->getEmail(),
            'phone' => $c->getPhone(),
        ], $customers));
    }

    #[Route('/api/customers/{id}', name: 'get_customer', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $merchant = $user->getMerchant();
        if (!$merchant) {
            return new JsonResponse(['error' => 'Merchant not found for user'], 404);
        }

        // Secure lookup: customer must belong to current merchant
        $customer = $this->entityManager->getRepository(Customer::class)->findByIdAndMerchant($id, $merchant);
        if (!$customer) {
            // Return 404 for both "not found" and "not authorized" to prevent info leakage
            return new JsonResponse(['error' => 'Customer not found'], 404);
        }

        return new JsonResponse([
            'id' => $customer->getId(),
            'name' => $customer->getName(),
            'email' => $customer->getEmail(),
            'phone' => $customer->getPhone(),
        ]);
    }

    #[Route('/api/customers/{id}', name: 'update_customer', methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $merchant = $user->getMerchant();
        if (!$merchant) {
            return new JsonResponse(['error' => 'Merchant not found for user'], 404);
        }

        // Secure lookup: customer must belong to current merchant
        $customer = $this->entityManager->getRepository(Customer::class)->findByIdAndMerchant($id, $merchant);
        if (!$customer) {
            return new JsonResponse(['error' => 'Customer not found'], 404);
        }

        $data = json_decode($request->getContent(), true);
        if (isset($data['name'])) {
            $customer->setName($data['name']);
        }
        if (isset($data['email'])) {
            $customer->setEmail($data['email']);
        }
        if (array_key_exists('phone', $data)) {
            $customer->setPhone($data['phone']);
        }

        $this->entityManager->flush();

        return new JsonResponse([
            'id' => $customer->getId(),
            'name' => $customer->getName(),
            'email' => $customer->getEmail(),
            'phone' => $customer->getPhone(),
        ]);
    }

    #[Route('/api/customers/{id}', name: 'delete_customer', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $merchant = $user->getMerchant();
        if (!$merchant) {
            return new JsonResponse(['error' => 'Merchant not found for user'], 404);
        }

        // Secure lookup: customer must belong to current merchant
        $customer = $this->entityManager->getRepository(Customer::class)->findByIdAndMerchant($id, $merchant);
        if (!$customer) {
            return new JsonResponse(['error' => 'Customer not found'], 404);
        }

        // Keep loyalty cards and detach customer link before deleting customer.
        $this->entityManager->createQueryBuilder()
            ->update(LoyaltyCard::class, 'lc')
            ->set('lc.customer', ':nullCustomer')
            ->where('lc.customer = :customer')
            ->setParameter('nullCustomer', null)
            ->setParameter('customer', $customer)
            ->getQuery()
            ->execute();

        $this->entityManager->remove($customer);
        $this->entityManager->flush();

        return new JsonResponse(['success' => true]);
    }
}