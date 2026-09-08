<?php

namespace Tests\Feature\Auth;

use App\Livewire\Auth\Login;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use DatabaseTransactions;

    public function test_users_can_authenticate_using_their_username(): void
    {
        $user = User::factory()->create([
            'username' => 'babacar_diop',
            'password' => Hash::make('password'),
        ]);

        $this->get('/login')->assertOk();

        Livewire::test(Login::class)
            ->set('username', 'babacar_diop')
            ->set('password', 'password')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);

        // The authenticated session must actually satisfy the dashboard guard chain.
        $this->get(route('dashboard'))->assertOk();
    }

    public function test_the_login_page_renders_inside_the_full_auth_layout(): void
    {
        $response = $this->get('/login')->assertOk();

        // Regression: login.blade.php must wrap the component in <x-layouts.auth> so the
        // page ships a <head> with the compiled CSS/JS. Without it the form has no
        // Livewire runtime and silently reloads instead of authenticating.
        $response->assertSee('<!DOCTYPE html>', false);
        $response->assertSee('<title>Connexion</title>', false);
        $response->assertSee('</head>', false);
        $response->assertSeeLivewire(Login::class);
    }

    public function test_users_cannot_authenticate_with_their_email_address(): void
    {
        User::factory()->create([
            'email' => 'known@example.com',
            'password' => Hash::make('password'),
        ]);

        Livewire::test(Login::class)
            ->set('username', 'known@example.com')
            ->set('password', 'password')
            ->call('login')
            ->assertHasErrors('username');

        $this->assertGuest();
    }

    public function test_users_cannot_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create([
            'username' => 'fatou',
            'password' => Hash::make('password'),
        ]);

        Livewire::test(Login::class)
            ->set('username', $user->username)
            ->set('password', 'wrong-password')
            ->call('login')
            ->assertHasErrors('username');

        $this->assertGuest();
    }
}
