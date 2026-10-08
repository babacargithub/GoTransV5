<?php

namespace Tests\Feature;

use App\Livewire\BackOffice\DepartList;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class HomepageTest extends TestCase
{
    use DatabaseTransactions;

    public function test_guests_are_redirected_to_login_from_the_home_route(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_the_home_route_renders_the_depart_list_for_authenticated_users(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertOk()
            ->assertSeeLivewire(DepartList::class);
    }
}
