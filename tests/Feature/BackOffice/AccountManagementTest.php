<?php

namespace Tests\Feature\BackOffice;

use App\Enums\AccountTransactionCategory;
use App\Enums\AccountType;
use App\Enums\PermissionName;
use App\Livewire\BackOffice\AccountListPage;
use App\Models\Account;
use App\Models\User;
use App\Services\AccountService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class AccountManagementTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*' => Http::response([], 503)]);
    }

    /**
     * Any non-zero balance must be seeded through an actual ledger transaction
     * (not the `balance` column directly) — debit()/credit() always recompute
     * the cached balance from the transaction sum via updateBalanceFromLedger(),
     * which would otherwise silently wipe out a balance set only on the column.
     */
    private function makeAccount(string $name = 'Compte Test', int $balance = 0): Account
    {
        $account = Account::create([
            'name' => $name,
            'account_type' => AccountType::Management,
            'balance' => 0,
            'is_active' => true,
        ]);

        if ($balance > 0) {
            app(AccountService::class)->credit($account, $balance, 'Solde initial');
        }

        return $account->fresh();
    }

    public function test_the_accounts_page_renders_each_account_as_a_card_with_its_actions(): void
    {
        $account = $this->makeAccount('Compte Carte Test', 12_500);

        $this->actingAs(User::factory()->create())
            ->get(route('back-office.comptes.index'))
            ->assertOk()
            ->assertSee('Compte Carte Test')
            ->assertSee('12 500 FCFA')
            ->assertSee("openAccountTransactions({$account->id})", false)
            ->assertSee("openAccountTransfer({$account->id})", false)
            ->assertSee("openEditAccount({$account->id})", false)
            ->assertSee("askToDeleteAccount({$account->id})", false);
    }

    public function test_the_delete_button_is_disabled_only_for_accounts_with_a_balance(): void
    {
        $fundedAccount = $this->makeAccount('Compte Garni', 5_000);
        $emptyAccount = $this->makeAccount('Compte Vide');

        $html = $this->actingAs(User::factory()->create())
            ->get(route('back-office.comptes.index'))
            ->getContent();

        $this->assertStringContainsString("wire:click=\"askToDeleteAccount({$fundedAccount->id})\" disabled=\"disabled\"", $html);
        $this->assertStringNotContainsString("wire:click=\"askToDeleteAccount({$emptyAccount->id})\" disabled=\"disabled\"", $html);
    }

    public function test_the_cbao_account_card_shows_the_cbao_logo(): void
    {
        $this->makeAccount('Banque CBAO TEKKI PUB');

        $this->actingAs(User::factory()->create())
            ->get(route('back-office.comptes.index'))
            ->assertSee('images/banks/cbao.png', false);
    }

    public function test_the_accounts_page_offers_a_selection_widget_that_sums_balances(): void
    {
        $firstAccount = $this->makeAccount('Compte Somme A', 12_500);
        $secondAccount = $this->makeAccount('Compte Somme B', 7_500);

        $this->actingAs(User::factory()->create())
            ->get(route('back-office.comptes.index'))
            ->assertOk()
            ->assertSee('selectedIds', false)
            ->assertSee('Tout sélectionner')
            ->assertSee("\\u0022{$firstAccount->id}\\u0022:12500", false)
            ->assertSee("\\u0022{$secondAccount->id}\\u0022:7500", false)
            ->assertSee('Inclure Compte Somme A dans le total', false);
    }

    public function test_each_card_action_button_opens_its_modal(): void
    {
        $account = $this->makeAccount('Compte Modales');

        Livewire::actingAs(User::factory()->create())
            ->test(AccountListPage::class)
            ->call('openAccountTransactions', $account->id)
            ->assertSet('showAccountTransactionsModal', true)
            ->call('openAccountTransfer', $account->id)
            ->assertSet('showAccountTransferModal', true)
            ->call('openEditAccount', $account->id)
            ->assertSet('showAccountModal', true)
            ->assertSet('accountName', 'Compte Modales')
            ->call('askToDeleteAccount', $account->id)
            ->assertSet('showDeleteAccountModal', true);
    }

    public function test_an_unprivileged_user_cannot_create_an_account(): void
    {
        $user = $this->createUserWithPermissions([]);

        Livewire::actingAs($user)
            ->test(AccountListPage::class)
            ->call('openCreateAccount')
            ->set('accountName', 'Emprunt')
            ->set('accountType', AccountType::Management->value)
            ->call('saveAccount');

        $this->assertDatabaseMissing('accounts', ['name' => 'Emprunt']);
    }

    public function test_a_user_with_manage_accounts_permission_can_create_an_account(): void
    {
        $user = $this->createUserWithPermissions([PermissionName::ManageAccounts->value]);

        Livewire::actingAs($user)
            ->test(AccountListPage::class)
            ->call('openCreateAccount')
            ->set('accountName', 'Emprunt')
            ->set('accountType', AccountType::Management->value)
            ->call('saveAccount');

        $this->assertDatabaseHas('accounts', ['name' => 'Emprunt', 'account_type' => AccountType::Management->value]);
    }

    public function test_an_account_cannot_be_deleted_while_its_balance_is_non_zero(): void
    {
        $user = $this->createUserWithFullAccess();
        $account = $this->makeAccount('Emprunt', 10_000);

        Livewire::actingAs($user)
            ->test(AccountListPage::class)
            ->call('askToDeleteAccount', $account->id)
            ->call('confirmDeleteAccount');

        $this->assertModelExists($account);
    }

    public function test_an_account_with_zero_balance_can_be_deleted(): void
    {
        $user = $this->createUserWithFullAccess();
        $account = $this->makeAccount('Emprunt', 0);

        Livewire::actingAs($user)
            ->test(AccountListPage::class)
            ->call('askToDeleteAccount', $account->id)
            ->call('confirmDeleteAccount');

        $this->assertModelMissing($account);
    }

    public function test_transfer_between_accounts_moves_the_balance(): void
    {
        $user = $this->createUserWithFullAccess();
        $fromAccount = $this->makeAccount('Compte A', 50_000);
        $toAccount = $this->makeAccount('Compte B', 0);

        Livewire::actingAs($user)
            ->test(AccountListPage::class)
            ->call('openAccountTransfer', $fromAccount->id)
            ->set('transferToAccountId', $toAccount->id)
            ->set('accountTransferAmount', 20_000)
            ->set('accountTransferLabel', 'Réallocation')
            ->call('saveAccountTransfer')
            ->assertHasNoErrors();

        $this->assertSame(30_000, $fromAccount->fresh()->balance);
        $this->assertSame(20_000, $toAccount->fresh()->balance);
    }

    public function test_account_transactions_modal_exposes_each_transactions_category(): void
    {
        $user = $this->createUserWithFullAccess();
        $account = $this->makeAccount('Ventes de billets', 0);
        Account::where('id', $account->id)->update(['account_type' => AccountType::TicketSales->value]);
        app(AccountService::class)->credit($account->fresh(), 50_000, 'Billets du jour');

        $component = Livewire::actingAs($user)
            ->test(AccountListPage::class)
            ->call('openAccountTransactions', $account->id);

        $transaction = $component->get('accountTransactionsForModal')['transactions'][0];

        $this->assertSame(AccountTransactionCategory::Revenue->value, $transaction['category']);
        $this->assertSame(AccountTransactionCategory::Revenue->label(), $transaction['categoryLabel']);
        $this->assertSame(AccountTransactionCategory::Revenue->badgeColor(), $transaction['categoryColor']);
    }
}
