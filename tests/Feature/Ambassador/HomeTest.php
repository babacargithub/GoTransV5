<?php

namespace Tests\Feature\Ambassador;

use App\Enums\AmbassadorEarningStatus;
use App\Livewire\Ambassador\Home;
use App\Models\Ambassador;
use App\Models\AmbassadorEarning;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\Concerns\CreatesCustomersWithBookings;
use Tests\TestCase;

class HomeTest extends TestCase
{
    use CreatesCustomersWithBookings;
    use DatabaseTransactions;

    private function ambassadorUrl(): string
    {
        return 'http://'.config('app.ambassador_domain').'/';
    }

    /**
     * @param  array<string, mixed>  $ambassadorAttributes
     * @return array{user: User, ambassador: Ambassador}
     */
    private function createLoggedInCapableAmbassador(array $ambassadorAttributes = []): array
    {
        $user = User::factory()->create();
        $user->assignRole(Ambassador::ACCOUNT_ROLE_NAME);
        $ambassador = Ambassador::factory()->create(array_merge(['user_account_id' => $user->id], $ambassadorAttributes));

        return ['user' => $user, 'ambassador' => $ambassador];
    }

    private function createEarning(Ambassador $ambassador, AmbassadorEarningStatus $status, int $amount): void
    {
        AmbassadorEarning::factory()->create([
            'ambassador_id' => $ambassador->id,
            'status' => $status,
            'amount' => $amount,
            'paid_at' => $status === AmbassadorEarningStatus::Paid ? now() : null,
        ]);
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get($this->ambassadorUrl())->assertRedirect();
    }

    public function test_a_user_without_the_ambassador_role_is_forbidden(): void
    {
        $this->actingAs(User::factory()->create())
            ->get($this->ambassadorUrl())
            ->assertForbidden();
    }

    public function test_an_ambassador_sees_their_home_page(): void
    {
        ['user' => $user, 'ambassador' => $ambassador] = $this->createLoggedInCapableAmbassador(['first_name' => 'Awa']);

        $this->actingAs($user)
            ->get($this->ambassadorUrl())
            ->assertOk()
            ->assertSee('Bonjour Awa')
            ->assertSee($ambassador->code);
    }

    public function test_a_user_with_the_ambassador_role_but_no_ambassador_profile_gets_a_404(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Ambassador::ACCOUNT_ROLE_NAME);

        $this->actingAs($user)->get($this->ambassadorUrl())->assertNotFound();
    }

    public function test_the_balance_card_shows_total_available_pending_and_paid_amounts(): void
    {
        ['user' => $user, 'ambassador' => $ambassador] = $this->createLoggedInCapableAmbassador();
        $this->createEarning($ambassador, AmbassadorEarningStatus::Approved, 1500);
        $this->createEarning($ambassador, AmbassadorEarningStatus::Pending, 500);
        $this->createEarning($ambassador, AmbassadorEarningStatus::Paid, 12000);
        $this->createEarning($ambassador, AmbassadorEarningStatus::Cancelled, 99000);

        Livewire::actingAs($user)->test(Home::class)
            ->assertSeeInOrder(['Solde total', '2 000', 'FCFA', 'Disponible', '1 500', 'FCFA', 'En attente', '500', 'FCFA', 'Déjà reçu', '12 000', 'FCFA'])
            ->assertDontSee('99 000')
            ->assertDontSee('111 000');
    }

    public function test_an_ambassador_without_earnings_sees_zero_balances(): void
    {
        ['user' => $user] = $this->createLoggedInCapableAmbassador();

        Livewire::actingAs($user)->test(Home::class)
            ->assertSeeInOrder(['Solde total', '0', 'FCFA', 'Disponible', '0', 'FCFA', 'En attente', '0', 'FCFA', 'Déjà reçu', '0', 'FCFA']);
    }

    public function test_the_balance_never_includes_the_earnings_of_another_ambassador(): void
    {
        ['user' => $user, 'ambassador' => $ambassador] = $this->createLoggedInCapableAmbassador();
        $this->createEarning($ambassador, AmbassadorEarningStatus::Approved, 1000);
        $this->createEarning(Ambassador::factory()->create(), AmbassadorEarningStatus::Approved, 345678);

        $component = Livewire::actingAs($user)->test(Home::class);

        $component->assertDontSee('345 678');
        $this->assertSame(1000, $component->instance()->accountBalance()->totalBalance);
    }

    public function test_the_acquired_customers_count_is_shown(): void
    {
        ['user' => $user, 'ambassador' => $ambassador] = $this->createLoggedInCapableAmbassador();
        foreach (range(1, 3) as $unused) {
            $this->createBookingOfCustomer($this->createCustomerBroughtBy($ambassador));
        }
        $this->createCustomerBroughtBy($ambassador);
        $this->createBookingOfCustomer($this->createCustomerBroughtBy($ambassador), isCancelled: true);

        $component = Livewire::actingAs($user)->test(Home::class);

        $component->assertSeeInOrder(['Clients amenés', '3']);
        $this->assertSame(3, $component->instance()->acquiredCustomersCount());
    }

    public function test_the_share_link_points_to_the_public_website_with_the_lower_case_code(): void
    {
        ['user' => $user] = $this->createLoggedInCapableAmbassador(['code' => 'Awa10']);

        $component = Livewire::actingAs($user)->test(Home::class);

        $this->assertStringEndsWith('?ref=awa10', $component->instance()->shareUrl());
        $this->assertStringContainsString(config('app.public_website_domain'), $component->instance()->shareUrl());
    }

    public function test_the_share_button_opens_a_message_dialog_with_the_default_message_and_the_share_link(): void
    {
        ['user' => $user] = $this->createLoggedInCapableAmbassador(['code' => 'AWA10']);

        $component = Livewire::actingAs($user)->test(Home::class);

        $component->assertSee('Partager le lien')
            ->assertSee('Votre message de partage')
            ->assertSee('Envoyer sur WhatsApp')
            ->assertSeeHtml(e(Home::DEFAULT_SHARE_MESSAGE))
            ->assertSeeHtml('ref=awa10');
    }

    public function test_the_copy_button_is_rendered_with_the_share_link(): void
    {
        ['user' => $user] = $this->createLoggedInCapableAmbassador(['code' => 'AWA10']);

        $component = Livewire::actingAs($user)->test(Home::class);

        $component->assertSee('Copier le lien')
            ->assertSee('Lien copié')
            ->assertSeeHtml('?ref=awa10');
    }
}
