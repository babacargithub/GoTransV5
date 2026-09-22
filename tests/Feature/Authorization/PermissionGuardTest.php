<?php

namespace Tests\Feature\Authorization;

use App\Enums\PermissionName;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The shared API controllers are guarded by the permission catalogue: a caller
 * without the matching permission is refused with a 403 before any work happens,
 * and a `full-access` holder passes every check.
 */
class PermissionGuardTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedPermissionCatalogue();
    }

    public function test_the_enum_guard_refuses_a_guest(): void
    {
        $this->expectException(AuthorizationException::class);

        PermissionName::CreateCustomer->authorizeForCurrentUser();
    }

    public function test_creating_a_customer_is_refused_without_the_permission(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $uniqueName = 'Guard'.uniqid();

        $this->postJson(route('customers.store'), [
            'nom' => $uniqueName,
            'prenom' => 'Awa',
            'phoneNumber' => 770000000,
        ])->assertForbidden();

        $this->assertDatabaseMissing('customers', ['nom' => $uniqueName]);
    }

    public function test_creating_a_customer_is_allowed_with_the_permission(): void
    {
        Sanctum::actingAs($this->createUserWithPermissions([PermissionName::CreateCustomer->value]));

        $uniqueName = 'Guard'.uniqid();

        $this->postJson(route('customers.store'), [
            'nom' => $uniqueName,
            'prenom' => 'Modou',
            'phoneNumber' => 770000001,
        ])->assertOk();

        $this->assertDatabaseHas('customers', ['nom' => $uniqueName]);
    }

    public function test_the_guard_runs_before_validation(): void
    {
        // No `create-departs` permission: the request is refused with a 403 even
        // though the body is empty and would otherwise fail validation with a 422.
        Sanctum::actingAs(User::factory()->create());

        $this->postJson(route('departs.store'), [])->assertForbidden();

        // With the permission, the same empty body now reaches validation.
        Sanctum::actingAs($this->createUserWithPermissions([PermissionName::CreateDeparts->value]));

        $this->postJson(route('departs.store'), [])->assertStatus(422);
    }

    public function test_a_full_access_user_passes_a_guard_it_was_not_explicitly_granted(): void
    {
        Sanctum::actingAs($this->createUserWithPermissions([PermissionName::FullAccess->value]));

        $uniqueName = 'Guard'.uniqid();

        $this->postJson(route('customers.store'), [
            'nom' => $uniqueName,
            'prenom' => 'Binta',
            'phoneNumber' => 770000002,
        ])->assertOk();

        $this->assertDatabaseHas('customers', ['nom' => $uniqueName]);
    }

    public function test_deleting_a_customer_is_refused_without_the_permission(): void
    {
        $customer = Customer::create(['nom' => 'Sarr', 'prenom' => 'Omar', 'phone_number' => 770000003]);

        Sanctum::actingAs($this->createUserWithPermissions([PermissionName::CreateCustomer->value]));

        $this->deleteJson(route('customers.destroy', $customer))->assertForbidden();

        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
    }
}
