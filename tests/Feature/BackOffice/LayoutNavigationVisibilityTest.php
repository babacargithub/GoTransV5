<?php

namespace Tests\Feature\BackOffice;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class LayoutNavigationVisibilityTest extends TestCase
{
    use DatabaseTransactions;

    public function test_finances_and_admin_menus_are_visible_to_a_full_access_user(): void
    {
        $user = $this->createUserWithFullAccess();

        $response = $this->actingAs($user)->get(route('back-office.departs.index'));

        $response->assertOk();
        $response->assertSee('Finances');
        $response->assertSee('Admin');
    }

    public function test_finances_and_admin_menus_are_hidden_from_a_user_without_full_access(): void
    {
        $user = $this->createUserWithPermissions([]);

        $response = $this->actingAs($user)->get(route('back-office.departs.index'));

        $response->assertOk();
        $response->assertDontSee('Finances');
        $response->assertDontSee('Admin');
    }
}
