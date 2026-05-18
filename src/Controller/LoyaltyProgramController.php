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
        $actorMerchant = $this->resolveActorMerchant();
        if (!$merchant || !$actorMerchant || $merchant !== $actorMerchant) {
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
                'card_background_image_url' => $program->getCardBackgroundImageUrl(),
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

        if (!in_array('ROLE_MERCHANT', $user->getRoles(), true)) {
            return new JsonResponse(['error' => 'Forbidden'], 403);
        }

        $merchant = $this->resolveActorMerchant();
        if (!$merchant instanceof Merchant) {
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
            'card_background_image_url' => $program->getCardBackgroundImageUrl(),
        ], 201);
    }

    #[Route('/api/loyalty_programs/{id}', name: 'update_loyalty_program', methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        if (!in_array('ROLE_MERCHANT', $user->getRoles(), true)) {
            return new JsonResponse(['error' => 'Forbidden'], 403);
        }

        $actorMerchant = $this->resolveActorMerchant();
        if (!$actorMerchant instanceof Merchant) {
            return new JsonResponse(['error' => 'Merchant not found'], 404);
        }

        $program = $this->entityManager->getRepository(LoyaltyProgram::class)->find($id);
        if (!$program || $program->getMerchant() !== $actorMerchant) {
            return new JsonResponse(['error' => 'Program not found'], 404);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return new JsonResponse(['error' => 'Invalid payload'], 400);
        }

        // During update, only keep explicitly allowed fields.
        $allowedFields = ['name', 'description', 'reward_description'];
        $safeData = array_intersect_key($data, array_flip($allowedFields));

        if (array_key_exists('name', $safeData)) {
            $program->setName($safeData['name']);
        }
        if (array_key_exists('description', $safeData)) {
            $program->setDescription($safeData['description']);
        }
        if (array_key_exists('reward_description', $safeData)) {
            $program->setRewardDescription($safeData['reward_description']);
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
            'card_background_image_url' => $program->getCardBackgroundImageUrl(),
        ]);
    }
    #[Route('/api/loyalty_programs/{id}', name: 'delete_loyalty_program', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        if (!in_array('ROLE_MERCHANT', $user->getRoles(), true)) {
            return new JsonResponse(['error' => 'Forbidden'], 403);
        }

        $actorMerchant = $this->resolveActorMerchant();
        if (!$actorMerchant instanceof Merchant) {
            return new JsonResponse(['error' => 'Merchant not found'], 404);
        }

        $program = $this->entityManager->getRepository(LoyaltyProgram::class)->find($id);
        if (!$program || $program->getMerchant() !== $actorMerchant) {
            return new JsonResponse(['error' => 'Program not found'], 404);
        }

        // Suppression en cascade des cartes associées (orphanRemoval déjà activé)
        $this->entityManager->remove($program);
        $this->entityManager->flush();

        return new JsonResponse(['success' => true]);
    }

    #[Route('/api/loyalty_programs/{id}/background-image', name: 'upload_loyalty_program_background_image', methods: ['POST'])]
    public function uploadBackgroundImage(int $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        if (!in_array('ROLE_MERCHANT', $user->getRoles(), true)) {
            return new JsonResponse(['error' => 'Forbidden'], 403);
        }

        $actorMerchant = $this->resolveActorMerchant();
        if (!$actorMerchant instanceof Merchant) {
            return new JsonResponse(['error' => 'Merchant not found'], 404);
        }

        $program = $this->entityManager->getRepository(LoyaltyProgram::class)->find($id);
        if (!$program || $program->getMerchant() !== $actorMerchant) {
            return new JsonResponse(['error' => 'Program not found'], 404);
        }

        $data = json_decode($request->getContent(), true);
        if (!isset($data['background_image'])) {
            return new JsonResponse(['error' => 'background_image required'], 400);
        }

        $backgroundImage = $data['background_image'];

        // Validate format
        if (!preg_match('/^data:image\/(png|jpe?g|webp);base64,([A-Za-z0-9+\/=\s]+)$/i', $backgroundImage, $matches)) {
            return new JsonResponse(['error' => 'Format invalide. Formats acceptés: PNG, JPG, JPEG, WebP'], 400);
        }

        // Decode and validate size (5MB max)
        $decodedImage = base64_decode(preg_replace('/\s+/', '', $matches[2]), true);
        if (strlen($decodedImage) > 5242880) { // 5MB
            return new JsonResponse(['error' => 'Fichier trop volumineux (max 5MB)'], 400);
        }

        $program->setCardBackgroundImageUrl($backgroundImage);
        $this->entityManager->flush();

        return new JsonResponse([
            'success' => true,
            'card_background_image_url' => $program->getCardBackgroundImageUrl(),
        ]);
    }

    #[Route('/api/loyalty_programs/{id}/background-image', name: 'delete_loyalty_program_background_image', methods: ['DELETE'])]
    public function deleteBackgroundImage(int $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        if (!in_array('ROLE_MERCHANT', $user->getRoles(), true)) {
            return new JsonResponse(['error' => 'Forbidden'], 403);
        }

        $actorMerchant = $this->resolveActorMerchant();
        if (!$actorMerchant instanceof Merchant) {
            return new JsonResponse(['error' => 'Merchant not found'], 404);
        }

        $program = $this->entityManager->getRepository(LoyaltyProgram::class)->find($id);
        if (!$program || $program->getMerchant() !== $actorMerchant) {
            return new JsonResponse(['error' => 'Program not found'], 404);
        }

        $program->setCardBackgroundImageUrl(null);
        $this->entityManager->flush();

        return new JsonResponse(['success' => true]);
    }

    private function resolveActorMerchant(): ?Merchant
    {
        $user = $this->getUser();
        if ($user === null) {
            return null;
        }

        $merchant = $user->getMerchant();
        if ($merchant instanceof Merchant) {
            return $merchant;
        }

        $roles = $user->getRoles();
        if (!in_array('ROLE_EQUIPIER', $roles, true) && !in_array('ROLE_MERCHANT', $roles, true)) {
            return null;
        }

        return $user->getCustomer()?->getStaffMerchant();
    }
}