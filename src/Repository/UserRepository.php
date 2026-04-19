<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    public function findOneByGoogleLogin(string $googleId, string $email): ?User
    {
        $normalizedEmail = mb_strtolower(trim($email));

        $users = $this->createQueryBuilder('u')
            ->andWhere('u.googleId = :googleId OR LOWER(u.email) = :email')
            ->setParameter('googleId', trim($googleId))
            ->setParameter('email', $normalizedEmail)
            ->orderBy('u.id', 'ASC')
            ->getQuery()
            ->getResult();

        foreach ($users as $user) {
            if (!$user instanceof User) {
                continue;
            }

            if ($user->getGoogleId() === trim($googleId)) {
                return $user;
            }
        }

        foreach ($users as $user) {
            if (!$user instanceof User) {
                continue;
            }

            if (mb_strtolower((string) $user->getEmail()) === $normalizedEmail) {
                return $user;
            }
        }

        return null;
    }

    public function findOneSuperAdminByGoogleLogin(string $googleId, string $email): ?User
    {
        $users = $this->createQueryBuilder('u')
            ->andWhere('u.googleId = :googleId OR LOWER(u.email) = :email')
            ->setParameter('googleId', trim($googleId))
            ->setParameter('email', mb_strtolower(trim($email)))
            ->orderBy('u.id', 'ASC')
            ->getQuery()
            ->getResult();

        foreach ($users as $user) {
            if (!$user instanceof User) {
                continue;
            }

            if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true) && $user->getGoogleId() === trim($googleId)) {
                return $user;
            }
        }

        foreach ($users as $user) {
            if (!$user instanceof User) {
                continue;
            }

            if (
                in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)
                && mb_strtolower((string) $user->getEmail()) === mb_strtolower(trim($email))
            ) {
                return $user;
            }
        }

        return null;
    }

    //    /**
    //     * @return User[] Returns an array of User objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('u')
    //            ->andWhere('u.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('u.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?User
    //    {
    //        return $this->createQueryBuilder('u')
    //            ->andWhere('u.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
