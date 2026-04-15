<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Customer;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class CustomerUserRelationTest extends TestCase
{
    public function testCustomerAndUserCanBeLinkedWithoutRecursion(): void
    {
        $user = new User();
        $customer = new Customer();

        $customer->setUser($user);

        self::assertSame($user, $customer->getUser());
        self::assertSame($customer, $user->getCustomer());
    }

    public function testCustomerAndUserCanBeUnlinkedWithoutRecursion(): void
    {
        $user = new User();
        $customer = new Customer();
        $customer->setUser($user);

        $customer->setUser(null);

        self::assertNull($customer->getUser());
        self::assertNull($user->getCustomer());
    }
}
