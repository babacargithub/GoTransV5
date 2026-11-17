<?php

namespace Tests\Feature;

use App\Enums\ExpenseTypeCode;
use App\Models\ExpenseType;
use App\Models\MonthlyExpenseBudget;
use App\Services\DepartProfitAllocationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OneTimeNovember2026DataFixMigrationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // The bulk sortie split commits through its own command, so it must never run against the
        // database these tests share: only the "not the original sortie" path is exercised here.
        DB::table('account_transactions')->where('id', 866)->update(['label' => 'Déjà rattachée']);
    }

    private function runMigration(): void
    {
        $migration = require database_path('migrations/2026_11_17_114918_one_time_attach_bus_rental_operation_and_plan_november_2026_costs.php');
        $migration->up();
    }

    private function plannedAmountOf(ExpenseTypeCode $code): ?int
    {
        return MonthlyExpenseBudget::query()
            ->where('expense_type_id', ExpenseType::idForCode($code))
            ->whereDate('month', '2026-11-01')
            ->value('amount');
    }

    public function test_it_plans_the_november_costs_without_overwriting_an_existing_amount(): void
    {
        MonthlyExpenseBudget::query()->whereDate('month', '2026-11-01')->delete();
        MonthlyExpenseBudget::create([
            'expense_type_id' => ExpenseType::idForCode(ExpenseTypeCode::Publicite),
            'month' => '2026-11-01',
            'amount' => 55_000,
        ]);

        $this->runMigration();
        $this->runMigration();

        $this->assertSame(55_000, $this->plannedAmountOf(ExpenseTypeCode::Publicite));
        $this->assertSame(130_000, $this->plannedAmountOf(ExpenseTypeCode::SalaireEmploye));
        $this->assertSame(30_000, $this->plannedAmountOf(ExpenseTypeCode::TransportEmploye));
        $this->assertSame(10_000, $this->plannedAmountOf(ExpenseTypeCode::CreditEtPasseInternet));
        $this->assertSame(4, MonthlyExpenseBudget::query()->whereDate('month', '2026-11-01')->count());
    }

    public function test_it_leaves_the_bus_rental_operations_alone_when_the_bulk_sortie_is_not_the_original_one(): void
    {
        $sortieAmountBefore = (int) DB::table('account_transactions')->where('id', 866)->value('amount');
        $busRentalOperationsBefore = DB::table('account_transactions')->where('expense_type_id', ExpenseType::idForCode(ExpenseTypeCode::LocationDeBus))->selectRaw('count(*) n, sum(amount) s')->first();

        $this->runMigration();

        $this->assertEquals($busRentalOperationsBefore, DB::table('account_transactions')->where('expense_type_id', ExpenseType::idForCode(ExpenseTypeCode::LocationDeBus))->selectRaw('count(*) n, sum(amount) s')->first());
        $this->assertSame($sortieAmountBefore, (int) DB::table('account_transactions')->where('id', 866)->value('amount'));
    }

    public function test_it_allocates_profit_only_for_the_departs_covered_by_the_bulk_sortie(): void
    {
        $coveredDepartIds = [4256, 4262, 4270, 4257];
        $allocationCountOfOtherDeparts = fn (): int => DB::table('account_transactions')
            ->where('account_transactions.reference_type', DepartProfitAllocationService::REFERENCE_TYPE)
            ->join('depart_cost_reports', 'depart_cost_reports.id', '=', 'account_transactions.reference_id')
            ->whereNotIn('depart_cost_reports.depart_id', $coveredDepartIds)
            ->count();
        $chargesAccountId = DB::table('accounts')->where('name', DepartProfitAllocationService::CHARGES_ACCOUNT_NAME)->value('id');

        $otherDepartsAllocationsBefore = $allocationCountOfOtherDeparts();

        $this->runMigration();

        $this->assertSame($otherDepartsAllocationsBefore, $allocationCountOfOtherDeparts());

        $chargesAfterFirstRun = DB::table('accounts')->where('id', $chargesAccountId)->value('balance');

        $this->runMigration();

        $this->assertSame($chargesAfterFirstRun, DB::table('accounts')->where('id', $chargesAccountId)->value('balance'));
    }

    public function test_it_reclassifies_the_two_cancelling_revenue_operations_as_internal_without_moving_money(): void
    {
        $amountsBefore = DB::table('account_transactions')->whereIn('id', [863, 864])->orderBy('id')->pluck('amount')->all();

        $this->runMigration();
        $this->runMigration();

        $this->assertSame(['INTERNAL', 'INTERNAL'], DB::table('account_transactions')->whereIn('id', [863, 864])->orderBy('id')->pluck('category')->all());
        $this->assertSame($amountsBefore, DB::table('account_transactions')->whereIn('id', [863, 864])->orderBy('id')->pluck('amount')->all());
    }
}
