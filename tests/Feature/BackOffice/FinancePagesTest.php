<?php

namespace Tests\Feature\BackOffice;

use App\Livewire\BackOffice\CaisseBalancesPage;
use App\Livewire\BackOffice\OrangeMoneyPage;
use App\Livewire\BackOffice\WavePaymentsPage;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class FinancePagesTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Keep the provider (Wave / Orange Money) HTTP calls from leaving the test
        // process; every provider endpoint fails so the pages exercise their
        // graceful-degradation path.
        Http::fake(['*' => Http::response([], 503)]);
    }

    public function test_guests_are_redirected_from_the_finance_pages(): void
    {
        $this->get(route('back-office.caisses.index'))->assertRedirect(route('login'));
        $this->get(route('back-office.paiements-om.index'))->assertRedirect(route('login'));
        $this->get(route('back-office.paiements-wave.index'))->assertRedirect(route('login'));
    }

    public function test_the_caisses_page_renders_and_degrades_when_providers_are_unreachable(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(CaisseBalancesPage::class)
            ->assertOk()
            ->assertSee('Solde des caisses')
            ->assertSee('Indisponible')
            ->assertSet('waveBalance', null)
            ->assertSet('orangeMoneyBalance', null);
    }

    public function test_the_paiements_om_page_renders_and_degrades_when_the_api_is_unreachable(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(OrangeMoneyPage::class)
            ->assertOk()
            ->assertSee('Paiements OM')
            ->assertSet('orangeMoneyBalance', null)
            ->assertSet('orangeMoneyTransactions', null);
    }

    public function test_the_withdraw_form_requires_every_field(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(OrangeMoneyPage::class)
            ->call('openWithdrawModal')
            ->assertSet('showWithdrawModal', true)
            ->call('confirmWithdraw')
            ->assertHasErrors(['withdrawAmount', 'withdrawPhoneNumber', 'withdrawSecretCode']);
    }

    public function test_the_withdraw_form_rejects_a_wrong_secret_code(): void
    {
        config(['app.om_secret_code' => 'the-real-code']);

        Livewire::actingAs(User::factory()->create())
            ->test(OrangeMoneyPage::class)
            ->call('openWithdrawModal')
            ->set('withdrawAmount', 5000)
            ->set('withdrawPhoneNumber', '771234567')
            ->set('withdrawSecretCode', 'wrong-code')
            ->call('confirmWithdraw')
            ->assertHasNoErrors()
            ->assertSet('showWithdrawModal', true)
            ->assertSet('withdrawErrorMessage', 'bad request: invalid withdraw secret code');
    }

    public function test_the_wave_page_shows_a_placeholder(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(WavePaymentsPage::class)
            ->assertOk()
            ->assertSee('Bientôt disponible');
    }
}
