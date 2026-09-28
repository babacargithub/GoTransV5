<?php

namespace Tests\Feature\BackOffice;

use App\Enums\AccountType;
use App\Enums\CaisseCode;
use App\Enums\PermissionName;
use App\Livewire\BackOffice\CaisseBalancesPage;
use App\Models\Account;
use App\Models\Caisse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class CaisseManagementTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Keep the Wave / Orange Money provider calls used by the "Aperçu" tab
        // out of the test process.
        Http::fake(['*' => Http::response([], 503)]);
    }

    public function test_an_unprivileged_user_cannot_create_a_caisse(): void
    {
        $user = $this->createUserWithPermissions([]);

        Livewire::actingAs($user)
            ->test(CaisseBalancesPage::class)
            ->set('activeTab', 'caisses')
            ->call('openCreateCaisse')
            ->set('caisseName', 'Caisse gare Nord')
            ->call('saveCaisse');

        $this->assertDatabaseMissing('caisses', ['name' => 'Caisse gare Nord']);
    }

    public function test_a_user_with_manage_caisse_permission_can_create_a_caisse(): void
    {
        $user = $this->createUserWithPermissions([PermissionName::ManageCaisse->value]);

        Livewire::actingAs($user)
            ->test(CaisseBalancesPage::class)
            ->set('activeTab', 'caisses')
            ->call('openCreateCaisse')
            ->set('caisseName', 'Caisse gare Nord')
            ->call('saveCaisse');

        $this->assertDatabaseHas('caisses', ['name' => 'Caisse gare Nord', 'code' => null]);
    }

    public function test_a_system_caisse_cannot_be_deleted_even_at_zero_balance(): void
    {
        $user = $this->createUserWithFullAccess();
        $ticketCash = Caisse::findByCode(CaisseCode::TicketCash);

        Livewire::actingAs($user)
            ->test(CaisseBalancesPage::class)
            ->set('activeTab', 'caisses')
            ->call('askToDeleteCaisse', $ticketCash->id)
            ->call('confirmDeleteCaisse');

        $this->assertModelExists($ticketCash);
    }

    public function test_an_adhoc_caisse_cannot_be_deleted_while_its_balance_is_non_zero(): void
    {
        $user = $this->createUserWithFullAccess();
        $caisse = Caisse::create(['name' => 'Caisse gare Nord']);
        $caisse->transactions()->create(['amount' => 5000, 'transaction_type' => 'DEPOSIT']);
        $caisse->updateBalanceFromLedger();

        Livewire::actingAs($user)
            ->test(CaisseBalancesPage::class)
            ->set('activeTab', 'caisses')
            ->call('askToDeleteCaisse', $caisse->id)
            ->call('confirmDeleteCaisse');

        $this->assertModelExists($caisse);
    }

    public function test_entree_de_caisse_deposits_into_the_caisse_and_credits_the_chosen_account(): void
    {
        $user = $this->createUserWithFullAccess();
        $caisse = Caisse::findByCode(CaisseCode::Principale);
        $account = Account::create(['name' => 'Emprunt', 'account_type' => AccountType::Management, 'balance' => 0, 'is_active' => true]);

        Livewire::actingAs($user)
            ->test(CaisseBalancesPage::class)
            ->set('activeTab', 'caisses')
            ->call('openEntreeDeCaisse', $caisse->id)
            ->set('entreeAccountId', $account->id)
            ->set('entreeAmount', 100_000)
            ->set('entreeLabel', 'Emprunt partenaire')
            ->call('saveEntreeDeCaisse')
            ->assertHasNoErrors();

        $this->assertSame(100_000, $caisse->fresh()->balance);
        $this->assertSame(100_000, $account->fresh()->balance);
    }

    public function test_sortie_de_caisse_is_rejected_when_the_caisse_balance_is_insufficient(): void
    {
        $user = $this->createUserWithFullAccess();
        $caisse = Caisse::findByCode(CaisseCode::Principale);
        $account = Account::create(['name' => 'Charges', 'account_type' => AccountType::Expense, 'balance' => 0, 'is_active' => true]);

        Livewire::actingAs($user)
            ->test(CaisseBalancesPage::class)
            ->set('activeTab', 'caisses')
            ->call('openSortieDeCaisse', $caisse->id)
            ->set('sortieAmount', 10_000)
            ->set('sortieLabel', 'Paiement fournisseur')
            ->set('sortieAccountIds', [$account->id])
            ->call('saveSortieDeCaisse');

        $this->assertSame(0, $caisse->fresh()->balance);
    }

    public function test_transfer_between_caisses_moves_the_balance(): void
    {
        $user = $this->createUserWithFullAccess();
        $fromCaisse = Caisse::findByCode(CaisseCode::TicketCash);
        $toCaisse = Caisse::findByCode(CaisseCode::Principale);
        $fromCaisse->transactions()->create(['amount' => 50_000, 'transaction_type' => 'DEPOSIT']);
        $fromCaisse->updateBalanceFromLedger();

        Livewire::actingAs($user)
            ->test(CaisseBalancesPage::class)
            ->set('activeTab', 'caisses')
            ->call('openCaisseTransfer', $fromCaisse->id)
            ->set('transferToCaisseId', $toCaisse->id)
            ->set('transferAmount', 20_000)
            ->call('saveCaisseTransfer')
            ->assertHasNoErrors();

        $this->assertSame(30_000, $fromCaisse->fresh()->balance);
        $this->assertSame(20_000, $toCaisse->fresh()->balance);
    }

    public function test_toggling_the_day_lock_locks_then_unlocks_the_caisse(): void
    {
        $user = $this->createUserWithFullAccess();
        $caisse = Caisse::findByCode(CaisseCode::Principale);

        Livewire::actingAs($user)
            ->test(CaisseBalancesPage::class)
            ->set('activeTab', 'caisses')
            ->call('toggleCaisseDayLock', $caisse->id);

        $this->assertTrue($caisse->fresh()->isLockedForToday());

        Livewire::actingAs($user)
            ->test(CaisseBalancesPage::class)
            ->set('activeTab', 'caisses')
            ->call('toggleCaisseDayLock', $caisse->id);

        $this->assertFalse($caisse->fresh()->isLockedForToday());
    }
}
