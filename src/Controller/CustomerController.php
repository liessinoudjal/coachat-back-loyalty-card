<?php

namespace App\Controller;

use App\Entity\Customer;
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

        $data = json_decode($request->getContent(), true);
        if (!isset($data['name']) || !isset($data['email'])) {
            return new JsonResponse(['error' => 'name and email required'], 400);
        }

        $customer = new Customer();
        $customer->setName($data['name']);
        $customer->setEmail($data['email']);
        $customer->setPhone($data['phone'] ?? null);

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
    public function list(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $customers = $this->entityManager->getRepository(Customer::class)->findAll();

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

        $customer = $this->entityManager->getRepository(Customer::class)->find($id);
        if (!$customer) {
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

        $customer = $this->entityManager->getRepository(Customer::class)->find($id);
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

        $customer = $this->entityManager->getRepository(Customer::class)->find($id);
        if (!$customer) {
            return new JsonResponse(['error' => 'Customer not found'], 404);
        }

        $this->entityManager->remove($customer);
        $this->entityManager->flush();

        return new JsonResponse(['success' => true]);
    }
}