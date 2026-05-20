<?php

namespace App\EventSubscriber;

use App\Entity\User;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

final class JwtCreatedSubscriber
{
    public function __construct(
        private readonly RoleHierarchyInterface $roleHierarchy,
    ) {
    }

    #[AsEventListener(event: 'lexik_jwt_authentication.on_jwt_created')]
    public function onJwtCreated(JWTCreatedEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        $roles = array_values(array_unique($this->roleHierarchy->getReachableRoleNames($user->getRoles())));
        sort($roles);

        $merchant = $user->getMerchant();
        $equipierMerchant = $user->getCustomer()?->getStaffMerchant();

        $data = $event->getData();
        $data['roles'] = $roles;
        $data['all_roles'] = $roles;
        $data['is_equipier'] = in_array('ROLE_EQUIPIER', $roles, true);
        $data['is_owner'] = $merchant !== null;
        $data['merchant_id'] = $merchant?->getId()?->toRfc4122();
        $data['equipier_merchant_id'] = $equipierMerchant?->getId()?->toRfc4122();
        $data['customer_id'] = $user->getCustomer()?->getId();

        $event->setData($data);
    }
}
