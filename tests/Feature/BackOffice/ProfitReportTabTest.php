<?php

namespace Tests\Feature\BackOffice;

use App\Enums\AccountTransactionCategory;
use App\Enums\AccountType;
use App\Livewire\BackOffice\CaisseBalancesPage;
use App\Models\Account;
use App\Models\Caisse;
use App\Models\ExpenseType;
use App\Services\AccountService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ProfitReportTabTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*' => Http::response([], 503)]);
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

    public function test_profit_report_defaults_to_the_current_month(): void
    {
        $user = $this->createUserWithFullAccess();

        Livewire::actingAs($user)
            ->test(CaisseBalancesPage::class)
            ->set('activeTab', 'revenus')
            ->assertSet('profitReportDateFrom', now()->startOfMonth()->toDateString())
            ->assertSet('profitReportDateTo', now()->endOfMonth()->toDateString());
    }

    public function test_profit_report_sums_revenue_and_expenses_by_account_within_the_selected_range(): void
    {
        $user = $this->createUserWithFullAccess();
        $accountService = app(AccountService::class);

        $ticketSales = $this->makeAccount('Ventes de billets (test revenus)', AccountType::TicketSales);
        $salaires = $this->makeAccount('Salaires (test revenus)', AccountType::Expense);

        $accountService->credit($ticketSales, 100_000, 'Billets');
        $accountService->transferBetweenAccounts($ticketSales, $salaires, 30_000, 'Provision paie');
        $accountService->debit($salaires, 30_000, 'Paie', referenceType: 'SORTIE_DE_CAISSE');

        $component = Livewire::actingAs($user)
            ->test(CaisseBalancesPage::class)
            ->set('activeTab', 'revenus')
            ->set('profitReportDateFrom', now()->startOfMonth()->toDateString())
            ->set('profitReportDateTo', now()->endOfMonth()->toDateString())
            ->call('applyProfitReportFilter');

        $report = $component->get('profitReport');

        $revenueRow = collect($report['revenueByAccount'])->firstWhere('accountId', $ticketSales->id);
        $expenseRow = collect($report['expensesByAccount'])->firstWhere('accountId', $salaires->id);

        $this->assertSame(100_000, $revenueRow['amount']);
        $this->assertSame(30_000, $expenseRow['amount']);
        $this->assertSame($report['totalRevenue'] - $report['totalExpenses'], $report['profit']);
    }

    public function test_profit_report_excludes_transactions_outside_the_selected_date_range(): void
    {
        $user = $this->createUserWithFullAccess();
        $accountService = app(AccountService::class);

        $ticketSales = $this->makeAccount('Ventes de billets (hors période)', AccountType::TicketSales);
        $accountService->credit($ticketSales, 100_000, 'Billets');

        $component = Livewire::actingAs($user)
            ->test(CaisseBalancesPage::class)
            ->set('activeTab', 'revenus')
            ->set('profitReportDateFrom', now()->addDays(10)->toDateString())
            ->set('profitReportDateTo', now()->addDays(20)->toDateString())
            ->call('applyProfitReportFilter');

        $report = $component->get('profitReport');

        $revenueRow = collect($report['revenueByAccount'])->firstWhere('accountId', $ticketSales->id);

        $this->assertNull($revenueRow);
    }

    public function test_balance_sheet_widget_lists_revenues_and_expenses_by_type_with_totals_and_net_result(): void
    {
        $user = $this->createUserWithFullAccess();
        $accountService = app(AccountService::class);
        $caisse = Caisse::create(['name' => 'Caisse bilan (test)', 'balance' => 0]);
        $ticketSales = $this->makeAccount('Ventes bilan (test)', AccountType::TicketSales);
        $airtimeType = ExpenseType::factory()->create(['name' => 'Airtime bilan (test)']);

        $accountService->processEntreeDeCaisse($caisse, $ticketSales, 200_000, 'Billets', categoryOverride: AccountTransactionCategory::Revenue);
        $accountService->processSortieDeCaisse($caisse, 50_000, 'Airtime', [$ticketSales->id], categoryOverride: AccountTransactionCategory::Expense, expenseTypeId: $airtimeType->id);

        Livewire::actingAs($user)
            ->test(CaisseBalancesPage::class)
            ->set('activeTab', 'revenus')
            ->assertSee('Bilan de la période')
            ->assertSee('Total revenus')
            ->assertSee('Total dépenses')
            ->assertSee('Résultat net')
            ->assertSee('Airtime bilan (test)')
            ->assertSee('50 000 FCFA');
    }

    public function test_balance_sheet_widget_shows_an_empty_expenses_section_when_there_are_none(): void
    {
        $user = $this->createUserWithFullAccess();

        Livewire::actingAs($user)
            ->test(CaisseBalancesPage::class)
            ->set('activeTab', 'revenus')
            ->set('profitReportDateFrom', now()->addYears(5)->toDateString())
            ->set('profitReportDateTo', now()->addYears(5)->addDay()->toDateString())
            ->call('applyProfitReportFilter')
            ->assertSee('Aucune dépense sur cette période');
    }
}
