<?php

namespace Tests\Feature;

use App\Livewire\Profile\Edit;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use DatabaseTransactions;

    public function test_profile_page_renders_for_authenticated_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/profile')
            ->assertOk();
    }

    public function test_user_can_update_profile_information(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(Edit::class)
            ->set('name', 'Nouveau Nom')
            ->set('email', $user->email)
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        $this->assertSame('Nouveau Nom', $user->fresh()->name);
    }

    public function test_two_factor_authentication_can_be_enabled_and_confirmed(): void
    {
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)
            ->test(Edit::class)
            ->call('enableTwoFactorAuthentication');

        $user->refresh();
        $this->assertNotNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at);

        $code = app(\PragmaRX\Google2FA\Google2FA::class)->getCurrentOtp(
            decrypt($user->two_factor_secret)
        );

        $component->set('twoFactorCode', $code)
            ->call('confirmTwoFactorAuthentication')
            ->assertHasNoErrors();

        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);
    }
}
