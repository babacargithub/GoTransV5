<?php

namespace Tests\Unit\Models;

use App\Models\User;
use PHPUnit\Framework\TestCase;

class UserResolveRolesTest extends TestCase
{
    public function test_it_expands_a_role_to_include_its_hierarchy(): void
    {
        $roles = User::resolveRoles(['ROLE_ADMIN']);

        $this->assertContains('ROLE_ADMIN', $roles);
        $this->assertContains('ROLE_EMPLOYEE', $roles);
        $this->assertContains('ROLE_USER', $roles);
        $this->assertContains('ROLE_AMBASSADOR', $roles);
    }

    public function test_it_can_be_called_more_than_once_without_redeclare_error(): void
    {
        User::resolveRoles(['ROLE_USER']);
        $roles = User::resolveRoles(['ROLE_OWNER']);

        $this->assertContains('ROLE_OWNER', $roles);
        $this->assertContains('ROLE_CEO', $roles);
        $this->assertContains('ROLE_SUPER_ADMIN', $roles);
    }
}
