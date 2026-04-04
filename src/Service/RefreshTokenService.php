<?php

namespace App\Service;

use App\Entity\RefreshToken;
use App\Entity\User;
use App\Repository\RefreshTokenRepository;
use Doctrine\ORM\EntityManagerInterface;

class RefreshTokenService
{
    private $repository;
    private $entityManager;
    private $refreshTokenTtl; // in seconds

    public function __construct(
        RefreshTokenRepository $repository,
        EntityManagerInterface $entityManager,
        int $refreshTokenTtl = 2592000 // 30 days
    ) {
        $this->repository = $repository;
        $this->entityManager = $entityManager;
        $this->refreshTokenTtl = $refreshTokenTtl;
    }

    public function createRefreshToken(User $user): RefreshToken
    {
        $refreshToken = new RefreshToken();
        $refreshToken->setUser($user);
        $refreshToken->setToken(bin2hex(random_bytes(32)));
        $refreshToken->setExpiresAt(
            (new \DateTime())->add(new \DateInterval('PT' . $this->refreshTokenTtl . 'S'))
        );

        $this->entityManager->persist($refreshToken);
        $this->entityManager->flush();

        return $refreshToken;
    }

    public function getValidRefreshToken(string $token): ?RefreshToken
    {
        return $this->repository->findValidByToken($token);
    }

    public function revokeRefreshToken(RefreshToken $refreshToken): void
    {
        $refreshToken->setRevoked(true);
        $this->entityManager->flush();
    }

    public function revokeUserRefreshTokens(User $user): void
    {
        foreach ($user->getRefreshTokens() as $refreshToken) {
            $this->revokeRefreshToken($refreshToken);
        }
    }
}