<?php

namespace Tests\Feature\BackOffice;

use App\Livewire\BackOffice\CaisseBalancesPage;
use App\Livewire\BackOffice\OrangeMoneyPage;
use App\Livewire\BackOffice\WavePaymentsPage;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Factory as HttpClientFactory;
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
            ->assertSee('Ventes de billets')
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

    public function test_the_om_transactions_show_the_orange_transaction_id_and_can_be_searched(): void
    {
        // setUp already registered a catch-all 503 stub, which would win over these.
        Http::swap(new HttpClientFactory);
        Http::fake([
            '*oauth/token' => Http::response(['access_token' => 'fake-token']),
            '*eWallet/v1/transactions*' => Http::response(['content' => [
                ['transactionId' => 'MP261008.1207.A33744', 'reference' => 'globesoft.1791461235', 'customer' => ['id' => '221771234567'], 'amount' => ['value' => 4040], 'status' => 'SUCCESS'],
                ['transactionId' => 'MP261008.1145.B11111', 'reference' => 'globesoft.1791459951', 'customer' => ['id' => '221785550000'], 'amount' => ['value' => 2000], 'status' => 'SUCCESS'],
            ]]),
            '*' => Http::response([], 503),
        ]);

        Livewire::actingAs(User::factory()->create())
            ->test(OrangeMoneyPage::class)
            ->assertSee('MP261008.1207.A33744')
            ->assertDontSee('globesoft.1791461235')
            ->set('transactionSearch', '77 123 45 67')
            ->assertSee('MP261008.1207.A33744')
            ->assertDontSee('MP261008.1145.B11111')
            ->set('transactionSearch', 'b11111')
            ->assertSee('MP261008.1145.B11111')
            ->assertDontSee('MP261008.1207.A33744')
            ->set('transactionSearch', 'nothing-matches')
            ->assertSee('Aucune transaction ne correspond');
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
