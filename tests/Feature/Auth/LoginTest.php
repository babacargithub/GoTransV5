<?php

namespace Tests\Feature\Auth;

use App\Enums\AmbassadorStatus;
use App\Livewire\Auth\Login;
use App\Models\Ambassador;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Livewire\Features\SupportTesting\Testable;
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
            ->assertRedirect(route('back-office.departs.index'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_returns_to_the_page_the_user_was_trying_to_reach(): void
    {
        User::factory()->create([
            'username' => 'fatou_sow',
            'password' => Hash::make('password'),
        ]);
        session()->put('url.intended', route('back-office.caisses.index'));

        Livewire::test(Login::class)
            ->set('username', 'fatou_sow')
            ->set('password', 'password')
            ->call('login')
            ->assertRedirect(route('back-office.caisses.index'));
    }

    public function test_an_already_authenticated_user_visiting_the_login_page_lands_on_the_departs_list(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/login')
            ->assertRedirect('/back-office/departs');
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

    /**
     * @param  array<string, mixed>  $ambassadorAttributes
     */
    private function createAmbassadorAccount(array $ambassadorAttributes = []): User
    {
        $user = User::factory()->create([
            'username' => 'ambassadrice_awa',
            'password' => Hash::make('password'),
        ]);
        $user->assignRole(Ambassador::ACCOUNT_ROLE_NAME);
        Ambassador::factory()->create(array_merge(['user_account_id' => $user->id], $ambassadorAttributes));

        return $user;
    }

    private function loginOnAmbassadorDomain(): Testable
    {
        config(['app.ambassador_domain' => request()->getHost()]);

        return Livewire::test(Login::class)
            ->set('username', 'ambassadrice_awa')
            ->set('password', 'password')
            ->call('login');
    }

    public function test_an_active_ambassador_signs_in_to_their_space(): void
    {
        $user = $this->createAmbassadorAccount();

        $this->loginOnAmbassadorDomain()
            ->assertHasNoErrors()
            ->assertRedirect(route('ambassador.home'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_suspended_ambassador_is_told_their_account_is_suspended(): void
    {
        $this->createAmbassadorAccount(['status' => AmbassadorStatus::Suspended]);

        $this->loginOnAmbassadorDomain()
            ->assertHasErrors(['username' => __('auth.ambassador_suspended')]);

        $this->assertGuest();
    }

    public function test_an_ambassador_with_an_expired_contract_is_told_their_contract_expired(): void
    {
        $this->createAmbassadorAccount(['contract_starts_at' => today()->subYear(), 'contract_ends_at' => today()->subDay()]);

        $this->loginOnAmbassadorDomain()
            ->assertHasErrors(['username' => __('auth.ambassador_contract_expired')]);

        $this->assertGuest();
    }

    public function test_a_suspended_ambassador_with_a_wrong_password_only_gets_the_generic_message(): void
    {
        $this->createAmbassadorAccount(['status' => AmbassadorStatus::Suspended]);
        config(['app.ambassador_domain' => request()->getHost()]);

        Livewire::test(Login::class)
            ->set('username', 'ambassadrice_awa')
            ->set('password', 'wrong-password')
            ->call('login')
            ->assertHasErrors(['username' => __('auth.failed')]);
    }
}
