<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Exceptions\InsufficientAccountBalanceException;
use App\Models\Account;
use App\Models\Caisse;
use App\Models\User;
use App\Services\AccountService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Ledger-integrity tests for AccountService: the paired caisse/account
 * operations must always preserve SUM(account.balance) == SUM(caisse.balance).
 */
class AccountServiceTest extends TestCase
{
    use DatabaseTransactions;

    private AccountService $accountService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accountService = app(AccountService::class);
    }

    private function makeCaisse(string $name = 'Caisse Test'): Caisse
    {
        return Caisse::create(['name' => $name, 'balance' => 0]);
    }

    private function makeAccount(string $name = 'Compte Test'): Account
    {
        return Account::create([
            'name' => $name,
            'account_type' => AccountType::Management,
            'balance' => 0,
            'is_active' => true,
        ]);
    }

    private function assertGlobalInvariantHolds(): void
    {
        $totalAccountBalance = (int) Account::sum('balance');
        $totalCaisseBalance = (int) Caisse::sum('balance');

        $this->assertSame($totalAccountBalance, $totalCaisseBalance);
    }

    public function test_single_entree_increases_caisse_and_account_balance_by_exact_amount(): void
    {
        $caisse = $this->makeCaisse();
        $account = $this->makeAccount();

        $this->accountService->processEntreeDeCaisse($caisse, $account, 100_000, 'Emprunt');

        $this->assertSame(100_000, $caisse->fresh()->balance);
        $this->assertSame(100_000, $account->fresh()->balance);
        $this->assertGlobalInvariantHolds();
    }

    public function test_two_consecutive_entrees_accumulate_on_the_same_caisse_and_account(): void
    {
        $caisse = $this->makeCaisse();
        $account = $this->makeAccount();

        $this->accountService->processEntreeDeCaisse($caisse, $account, 100_000, 'E1');
        $this->accountService->processEntreeDeCaisse($caisse, $account, 50_000, 'E2');

        $this->assertSame(150_000, $caisse->fresh()->balance);
        $this->assertSame(150_000, $account->fresh()->balance);
        $this->assertGlobalInvariantHolds();
    }

    public function test_entree_of_zero_amount_is_rejected(): void
    {
        $caisse = $this->makeCaisse();
        $account = $this->makeAccount();

        $this->expectException(InvalidArgumentException::class);

        $this->accountService->processEntreeDeCaisse($caisse, $account, 0, 'Zéro');
    }

    public function test_entree_records_the_caisse_transaction_deposit_type_and_amount(): void
    {
        $caisse = $this->makeCaisse();
        $account = $this->makeAccount();

        $this->accountService->processEntreeDeCaisse($caisse, $account, 75_000, 'Dépôt test');

        $transaction = $caisse->transactions()->first();
        $this->assertSame(75_000, $transaction->amount);
        $this->assertSame('DEPOSIT', $transaction->transaction_type->value);
        $this->assertSame('Dépôt test', $transaction->label);
    }

    public function test_entree_records_the_initiating_user_id_on_both_ledgers(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $caisse = $this->makeCaisse();
        $account = $this->makeAccount();

        $this->accountService->processEntreeDeCaisse($caisse, $account, 50_000, 'Test');

        $this->assertSame($user->id, $caisse->transactions()->first()->user_id);
        $this->assertSame($user->id, $account->transactions()->first()->user_id);
    }

    public function test_entree_records_a_null_user_id_when_no_user_is_explicitly_given_outside_a_request(): void
    {
        $caisse = $this->makeCaisse();
        $account = $this->makeAccount();

        $this->accountService->processEntreeDeCaisse($caisse, $account, 50_000, 'Automatique');

        $this->assertNull($caisse->transactions()->first()->user_id);
        $this->assertNull($account->transactions()->first()->user_id);
    }

    public function test_sortie_de_caisse_withdraws_from_caisse_and_debits_accounts_in_order(): void
    {
        $caisse = $this->makeCaisse();
        $accountA = $this->makeAccount('Compte A');
        $accountB = $this->makeAccount('Compte B');

        $this->accountService->processEntreeDeCaisse($caisse, $accountA, 30_000, 'Seed A');
        $this->accountService->processEntreeDeCaisse($caisse, $accountB, 20_000, 'Seed B');

        $this->accountService->processSortieDeCaisse($caisse, 40_000, 'Paiement', [$accountA->id, $accountB->id]);

        // Account A (30_000) is drained first, then account B absorbs the remaining 10_000.
        $this->assertSame(0, $accountA->fresh()->balance);
        $this->assertSame(10_000, $accountB->fresh()->balance);
        $this->assertSame(10_000, $caisse->fresh()->balance);
        $this->assertGlobalInvariantHolds();
    }

    public function test_transfer_between_accounts_moves_balance_without_touching_any_caisse(): void
    {
        $caisse = $this->makeCaisse();
        $accountA = $this->makeAccount('Compte A');
        $accountB = $this->makeAccount('Compte B');

        $this->accountService->processEntreeDeCaisse($caisse, $accountA, 100_000, 'Seed');
        $caisseBalanceBefore = $caisse->fresh()->balance;

        $this->accountService->transferBetweenAccounts($accountA, $accountB, 40_000, 'Réallocation');

        $this->assertSame(60_000, $accountA->fresh()->balance);
        $this->assertSame(40_000, $accountB->fresh()->balance);
        $this->assertSame($caisseBalanceBefore, $caisse->fresh()->balance);
        $this->assertGlobalInvariantHolds();
    }

    public function test_debit_rejects_an_amount_exceeding_the_account_balance(): void
    {
        $account = $this->makeAccount();

        $this->expectException(InsufficientAccountBalanceException::class);

        $this->accountService->debit($account, 1, 'Trop');
    }

    public function test_delete_account_is_rejected_when_balance_is_non_zero(): void
    {
        $caisse = $this->makeCaisse();
        $account = $this->makeAccount();
        $this->accountService->processEntreeDeCaisse($caisse, $account, 10_000, 'Seed');

        $this->expectException(\RuntimeException::class);

        $this->accountService->deleteAccount($account->fresh());
    }

    public function test_delete_account_succeeds_once_balance_is_zero(): void
    {
        $account = $this->makeAccount();

        $this->accountService->deleteAccount($account);

        $this->assertModelMissing($account);
    }
}
