<?php

namespace Tests\Feature;

use App\Enums\AccountTransactionCategory;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Caisse;
use App\Models\ExpenseType;
use App\Models\MonthlyExpenseBudget;
use App\Services\AccountService;
use App\Services\FixedCostCoverageService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FixedCostCoverageServiceTest extends TestCase
{
    use DatabaseTransactions;

    private Caisse $caisse;

    private Account $ticketSalesAccount;

    private Account $managementAccount;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*' => Http::response([], 503)]);

        $this->caisse = Caisse::create(['name' => 'Caisse charges fixes (test)', 'balance' => 0]);
        $this->ticketSalesAccount = Account::create(['name' => 'Ventes (test charges fixes)', 'account_type' => AccountType::TicketSales, 'balance' => 0, 'is_active' => true]);
        $this->managementAccount = Account::create(['name' => 'Gestion (test charges fixes)', 'account_type' => AccountType::Management, 'balance' => 0, 'is_active' => true]);
        app(AccountService::class)->processEntreeDeCaisse($this->caisse, $this->managementAccount, 1_000_000, 'Fonds');
    }

    private function earnRevenue(int $amount): void
    {
        app(AccountService::class)->credit($this->ticketSalesAccount, $amount, 'Billets');
    }

    private function spend(int $amount, ExpenseType $expenseType): void
    {
        app(AccountService::class)->processSortieDeCaisse(
            $this->caisse->fresh(), $amount, 'Dépense (test)', [$this->managementAccount->id],
            categoryOverride: AccountTransactionCategory::Expense,
            expenseTypeId: $expenseType->id,
        );
    }

    private function planCurrentMonth(ExpenseType $expenseType, int $amount): void
    {
        MonthlyExpenseBudget::factory()->create([
            'expense_type_id' => $expenseType->id,
            'month' => now()->startOfMonth()->toDateString(),
            'amount' => $amount,
        ]);
    }

    public function test_a_month_without_planned_fixed_costs_is_not_planned(): void
    {
        $coverage = app(FixedCostCoverageService::class)->coverageForMonth(now()->addYears(15));

        $this->assertFalse($coverage->isPlanned);
        $this->assertFalse($coverage->isCovered);
        $this->assertSame([], $coverage->lines);
    }

    public function test_fixed_costs_are_covered_once_the_margin_reaches_the_planned_total(): void
    {
        $salaryType = ExpenseType::factory()->create();
        $service = app(FixedCostCoverageService::class);
        $startingMargin = $service->coverageForMonth(now())->margin;

        $this->planCurrentMonth($salaryType, max($startingMargin, 0) + 100_000);

        $notYetCovered = $service->coverageForMonth(now());
        $this->assertTrue($notYetCovered->isPlanned);
        $this->assertFalse($notYetCovered->isCovered);
        $this->assertSame(max($startingMargin, 0) + 100_000 - $startingMargin, $notYetCovered->remainingToCover);

        $this->earnRevenue(100_000);

        $covered = $service->coverageForMonth(now());
        $this->assertTrue($covered->isCovered);
        $this->assertSame(0, $covered->remainingToCover);
        $this->assertSame(100, $covered->coveragePercent);
    }

    public function test_paying_a_fixed_cost_within_its_budget_does_not_reduce_the_margin_but_a_variable_cost_does(): void
    {
        $salaryType = ExpenseType::factory()->create();
        $busRentalType = ExpenseType::factory()->create();
        $this->planCurrentMonth($salaryType, 80_000);
        $service = app(FixedCostCoverageService::class);
        $startingMargin = $service->coverageForMonth(now())->margin;

        $this->spend(50_000, $salaryType);
        $afterFixedCostPayment = $service->coverageForMonth(now());
        $this->assertSame($startingMargin, $afterFixedCostPayment->margin);
        $this->assertSame(30_000, $afterFixedCostPayment->lines[0]['remaining']);

        $this->spend(20_000, $busRentalType);
        $this->assertSame($startingMargin - 20_000, $service->coverageForMonth(now())->margin);
    }

    public function test_the_part_paid_beyond_a_fixed_cost_budget_counts_as_a_variable_cost(): void
    {
        $salaryType = ExpenseType::factory()->create();
        $this->planCurrentMonth($salaryType, 40_000);
        $service = app(FixedCostCoverageService::class);
        $startingMargin = $service->coverageForMonth(now())->margin;

        $this->spend(55_000, $salaryType);

        $coverage = $service->coverageForMonth(now());
        $this->assertSame($startingMargin - 15_000, $coverage->margin);
        $this->assertSame(15_000, $coverage->lines[0]['overBudget']);
        $this->assertSame(0, $coverage->lines[0]['remaining']);
    }
}
