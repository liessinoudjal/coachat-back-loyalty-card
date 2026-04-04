<?php

namespace App\Controller;

use App\Entity\LoyaltyProgram;
use App\Entity\Merchant;
use App\Enum\LoyaltyProgramType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class LoyaltyProgramController extends AbstractController
{
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    #[Route('/api/loyalty_programs', name: 'get_loyalty_programs', methods: ['GET'])]
    public function getAll(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $merchantId = $request->query->get('merchant');
        if (!$merchantId) {
            return new JsonResponse(['error' => 'merchant parameter required'], 400);
        }

        $merchant = $this->entityManager->getRepository(Merchant::class)->find($merchantId);
        if (!$merchant || $merchant->getUser() !== $user) {
            return new JsonResponse(['error' => 'Merchant not found'], 404);
        }

        $programs = $merchant->getLoyaltyPrograms();
        $data = [];
        foreach ($programs as $program) {
            $data[] = [
                'id' => $program->getId(),
                'name' => $program->getName(),
                'description' => $program->getDescription(),
                'type' => $program->getType()->value,
                'points_per_euro' => $program->getPointsPerEuro(),
                'points_target' => $program->getPointsTarget(),
                'stamp_target' => $program->getStampTarget(),
                'reward_description' => $program->getRewardDescription(),
                'is_active' => $program->isActive(),
            ];
        }

        return new JsonResponse($data);
    }

    #[Route('/api/loyalty_programs', name: 'create_loyalty_program', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $merchant = $user->getMerchant();
        if (!$merchant) {
            return new JsonResponse(['error' => 'Merchant not found'], 404);
        }

        $data = json_decode($request->getContent(), true);
        if (!isset($data['name']) || !isset($data['type'])) {
            return new JsonResponse(['error' => 'name and type required'], 400);
        }

        // Validate type and corresponding target fields
        $type = LoyaltyProgramType::from($data['type']);
        if ($type === LoyaltyProgramType::POINTS && !isset($data['points_target'])) {
            return new JsonResponse(['error' => 'points_target required for POINTS type'], 400);
        }
        if ($type === LoyaltyProgramType::STAMP && !isset($data['stamp_target'])) {
            return new JsonResponse(['error' => 'stamp_target required for STAMP type'], 400);
        }

        $program = new LoyaltyProgram();
        $program->setName($data['name']);
        $program->setDescription($data['description'] ?? null);
        $program->setType($type);
        $program->setPointsPerEuro($data['points_per_euro'] ?? null);
        $program->setPointsTarget($data['points_target'] ?? null);
        $program->setStampTarget($data['stamp_target'] ?? null);
        $program->setRewardDescription($data['reward_description'] ?? null);
        $program->setIsActive($data['is_active'] ?? true);
        $program->setMerchant($merchant);

        $this->entityManager->persist($program);
        $this->entityManager->flush();

        return new JsonResponse([
            'id' => $program->getId(),
            'name' => $program->getName(),
            'description' => $program->getDescription(),
            'type' => $program->getType()->value,
            'points_per_euro' => $program->getPointsPerEuro(),
            'points_target' => $program->getPointsTarget(),
            'stamp_target' => $program->getStampTarget(),
            'reward_description' => $program->getRewardDescription(),
            'is_active' => $program->isActive(),
        ], 201);
    }

    #[Route('/api/loyalty_programs/{id}', name: 'update_loyalty_program', methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $program = $this->entityManager->getRepository(LoyaltyProgram::class)->find($id);
        if (!$program || $program->getMerchant()->getUser() !== $user) {
            return new JsonResponse(['error' => 'Program not found'], 404);
        }

        $data = json_decode($request->getContent(), true);
        if (isset($data['name'])) {
            $program->setName($data['name']);
        }
        if (isset($data['description'])) {
            $program->setDescription($data['description']);
        }
        if (isset($data['type'])) {
            $program->setType(LoyaltyProgramType::from($data['type']));
        }
        if (isset($data['points_per_euro'])) {
            $program->setPointsPerEuro($data['points_per_euro']);
        }
        if (isset($data['points_target'])) {
            $program->setPointsTarget($data['points_target']);
        }
        if (isset($data['stamp_target'])) {
            $program->setStampTarget($data['stamp_target']);
        }
        if (isset($data['reward_description'])) {
            $program->setRewardDescription($data['reward_description']);
        }
        if (isset($data['is_active'])) {
            $program->setIsActive($data['is_active']);
        }

        $this->entityManager->flush();

        return new JsonResponse([
            'id' => $program->getId(),
            'name' => $program->getName(),
            'description' => $program->getDescription(),
            'type' => $program->getType()->value,
            'points_per_euro' => $program->getPointsPerEuro(),
            'points_target' => $program->getPointsTarget(),
            'stamp_target' => $program->getStampTarget(),
            'reward_description' => $program->getRewardDescription(),
            'is_active' => $program->isActive(),
        ]);
    }
    #[Route('/api/loyalty_programs/{id}', name: 'delete_loyalty_program', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $program = $this->entityManager->getRepository(LoyaltyProgram::class)->find($id);
        if (!$program || $program->getMerchant()->getUser() !== $user) {
            return new JsonResponse(['error' => 'Program not found'], 404);
        }

        // Suppression en cascade des cartes associées (orphanRemoval déjà activé)
        $this->entityManager->remove($program);
        $this->entityManager->flush();

        return new JsonResponse(['success' => true]);
    }
}