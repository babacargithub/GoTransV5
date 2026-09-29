<?php

namespace Tests\Feature;

use App\Enums\AccountTransactionCategory;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Caisse;
use App\Services\AccountService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * AccountService::determineCategory() must persist the right REVENUE /
 * EXPENSE / INTERNAL category on every AccountTransaction row it creates —
 * see the doc block on that method for the classification rules.
 */
class AccountTransactionCategoryTest extends TestCase
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

    private function makeAccount(string $name, AccountType $type): Account
    {
        return Account::create([
            'name' => $name,
            'account_type' => $type,
            'balance' => 0,
            'is_active' => true,
        ]);
    }

    public function test_a_credit_into_an_income_account_is_categorized_as_revenue(): void
    {
        $caisse = $this->makeCaisse();
        $ticketSales = $this->makeAccount('Ventes de billets', AccountType::TicketSales);

        $this->accountService->processEntreeDeCaisse($caisse, $ticketSales, 50_000, 'Billets');

        $this->assertSame(
            AccountTransactionCategory::Revenue,
            $ticketSales->transactions()->first()->category,
        );
    }

    public function test_a_debit_out_of_an_expense_account_is_categorized_as_expense(): void
    {
        $caisse = $this->makeCaisse();
        $ticketSales = $this->makeAccount('Ventes de billets', AccountType::TicketSales);
        $salaires = $this->makeAccount('Salaires', AccountType::Expense);

        $this->accountService->processEntreeDeCaisse($caisse, $ticketSales, 100_000, 'Billets');
        $this->accountService->transferBetweenAccounts($ticketSales, $salaires, 50_000, 'Provision');
        $this->accountService->processSortieDeCaisse($caisse, 50_000, 'Paiement', [$salaires->id]);

        $debit = $salaires->transactions()->where('transaction_type', 'DEBIT')->first();
        $this->assertSame(AccountTransactionCategory::Expense, $debit->category);
    }

    public function test_a_credit_funding_an_expense_account_is_categorized_as_internal_not_expense(): void
    {
        $caisse = $this->makeCaisse();
        $ticketSales = $this->makeAccount('Ventes de billets', AccountType::TicketSales);
        $salaires = $this->makeAccount('Salaires', AccountType::Expense);

        $this->accountService->processEntreeDeCaisse($caisse, $ticketSales, 100_000, 'Billets');
        $this->accountService->transferBetweenAccounts($ticketSales, $salaires, 50_000, 'Provision');

        $credit = $salaires->transactions()->where('transaction_type', 'CREDIT')->first();
        $this->assertSame(AccountTransactionCategory::Internal, $credit->category);
    }

    public function test_a_transfer_out_of_an_income_account_is_categorized_as_internal_not_revenue(): void
    {
        $caisse = $this->makeCaisse();
        $ticketSales = $this->makeAccount('Ventes de billets', AccountType::TicketSales);
        $salaires = $this->makeAccount('Salaires', AccountType::Expense);

        $this->accountService->processEntreeDeCaisse($caisse, $ticketSales, 100_000, 'Billets');
        $this->accountService->transferBetweenAccounts($ticketSales, $salaires, 50_000, 'Provision');

        $debit = $ticketSales->transactions()->where('transaction_type', 'DEBIT')->first();
        $this->assertSame(AccountTransactionCategory::Internal, $debit->category);
    }

    public function test_a_management_account_movement_is_categorized_as_internal(): void
    {
        $caisse = $this->makeCaisse();
        $loan = $this->makeAccount('Emprunt', AccountType::Management);

        $this->accountService->processEntreeDeCaisse($caisse, $loan, 500_000, 'Emprunt bancaire');

        $this->assertSame(
            AccountTransactionCategory::Internal,
            $loan->transactions()->first()->category,
        );
    }
}
