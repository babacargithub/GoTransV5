<?php

namespace Tests\Feature;

use App\Enums\AccountTransactionCategory;
use App\Enums\AccountType;
use App\Enums\ExpenseTypeCode;
use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Caisse;
use App\Models\CaisseTransaction;
use App\Models\Depart;
use App\Models\DepartCostReport;
use App\Models\ExpenseType;
use App\Models\Trajet;
use App\Services\AccountService;
use App\Services\DepartCostReportPaymentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CreateCostReportsFromBusRentalOperationsTest extends TestCase
{
    use DatabaseTransactions;

    private Caisse $caisse;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->caisse = Caisse::create(['name' => 'Caisse octobre '.uniqid(), 'balance' => 0]);
        $this->account = Account::create(['name' => 'Compte octobre '.uniqid(), 'account_type' => AccountType::Management, 'balance' => 0, 'is_active' => true]);
        app(AccountService::class)->processEntreeDeCaisse($this->caisse, $this->account, 2_000_000, 'Fonds de test');
    }

    private function createDepart(string $date = '2026-10-20 08:00:00'): Depart
    {
        $depart = Depart::create([
            'name' => 'DEPART OCTOBRE '.uniqid(),
            'date' => $date,
            'trajet_id' => Trajet::query()->has('pointDeps')->has('destinations')->firstOrFail()->id,
            'closed' => false,
            'locked' => false,
            'canceled' => false,
        ]);
        $depart->buses()->create(['name' => 'Bus', 'nombre_place' => 57, 'ticket_price' => 10000, 'gp_ticket_price' => 6000, 'closed' => false]);

        return $depart;
    }

    private function createLegacyOperation(int $amount, string $date): AccountTransaction
    {
        $label = 'Location de bus octobre '.uniqid();
        app(AccountService::class)->processSortieDeCaisse(
            $this->caisse->fresh(), $amount, $label, [$this->account->id],
            categoryOverride: AccountTransactionCategory::Expense,
            expenseTypeId: ExpenseType::idForCode(ExpenseTypeCode::LocationDeBus),
        );
        $operation = AccountTransaction::where('account_id', $this->account->id)->where('label', $label)->sole();
        $operation->forceFill(['created_at' => $date])->save();
        CaisseTransaction::where('caisse_id', $this->caisse->id)->where('label', $label)->update(['created_at' => $date]);

        return $operation->fresh();
    }

    /**
     * @param  array<int, array{operationId: int, departId: int, amount: int}>  $allocations
     */
    private function runCommand(array $allocations, bool $apply): int
    {
        $mappingFile = tempnam(sys_get_temp_dir(), 'mapping');
        file_put_contents($mappingFile, json_encode(['allocations' => $allocations]));

        try {
            return $this->artisan('ledger:create-bus-rental-cost-reports', array_filter(['mappingFile' => $mappingFile, '--apply' => $apply]))->run();
        } finally {
            unlink($mappingFile);
        }
    }

    public function test_it_creates_cost_reports_with_an_anonymous_owner_and_splits_each_operation_per_depart(): void
    {
        $firstDepart = $this->createDepart();
        $secondDepart = $this->createDepart();
        $operation = $this->createLegacyOperation(100_000, '2026-10-21 10:00:00');
        $balanceBefore = $this->account->fresh()->balance;

        $exitCode = $this->runCommand([
            ['operationId' => $operation->id, 'departId' => $firstDepart->id, 'amount' => 60_000],
            ['operationId' => $operation->id, 'departId' => $secondDepart->id, 'amount' => 40_000],
        ], apply: true);

        $this->assertSame(0, $exitCode);

        foreach ([[$firstDepart, 60_000], [$secondDepart, 40_000]] as [$depart, $fee]) {
            $costReport = DepartCostReport::where('depart_id', $depart->id)->sole();
            $rental = $costReport->busRentals()->sole();
            $this->assertStringContainsString('Location de bus octobre', $costReport->notes);
            $this->assertSame('Annonyme', $rental->bus_owner_name);
            $this->assertSame($fee, $rental->rental_fee);
            $this->assertSame(0, $rental->remainingAmountToPay());

            $debit = AccountTransaction::where('reference_type', DepartCostReportPaymentService::ACCOUNT_REFERENCE_TYPE)->where('reference_id', $costReport->id)->sole();
            $this->assertSame($fee, $debit->amount);
            $this->assertSame('2026-10-21 10:00:00', $debit->created_at->format('Y-m-d H:i:s'));
            $this->assertSame(ExpenseType::idForCode(ExpenseTypeCode::LocationDeBus), $debit->expense_type_id);
            $this->assertSame(1, CaisseTransaction::where('caisse_id', $this->caisse->id)->where('label', $debit->label)->where('amount', $fee)->where('created_at', '2026-10-21 10:00:00')->count());
        }

        $this->assertSame($balanceBefore, $this->account->fresh()->balance);
        $this->assertSame($balanceBefore, $this->caisse->fresh()->balance);
        $this->assertTrue(app(AccountService::class)->isGlobalInvariantSatisfied());
        $this->assertSame(1, AccountTransaction::where('id', $operation->id)->count(), 'The original operation is reused, never deleted.');
    }

    public function test_what_an_operation_pays_beyond_its_allocations_stays_on_it_and_a_depart_can_be_paid_by_several_operations(): void
    {
        $depart = $this->createDepart();
        $firstOperation = $this->createLegacyOperation(56_000, '2026-10-11 08:00:00');
        $secondOperation = $this->createLegacyOperation(60_000, '2026-10-13 22:00:00');

        $this->runCommand([
            ['operationId' => $firstOperation->id, 'departId' => $depart->id, 'amount' => 30_000],
            ['operationId' => $secondOperation->id, 'departId' => $depart->id, 'amount' => 60_000],
        ], apply: true);

        $this->assertSame(90_000, DepartCostReport::where('depart_id', $depart->id)->sole()->busRentals()->sole()->rental_fee);
        $this->assertSame(26_000, $firstOperation->fresh()->amount);
        $this->assertNull($firstOperation->fresh()->reference_id);
        $this->assertSame(1, CaisseTransaction::where('caisse_id', $this->caisse->id)->where('amount', 26_000)->count());
        $this->assertTrue(app(AccountService::class)->isGlobalInvariantSatisfied());
    }

    public function test_it_only_simulates_without_the_apply_option(): void
    {
        $depart = $this->createDepart();
        $operation = $this->createLegacyOperation(100_000, '2026-10-21 10:00:00');

        $this->assertSame(0, $this->runCommand([['operationId' => $operation->id, 'departId' => $depart->id, 'amount' => 100_000]], apply: false));

        $this->assertSame(0, DepartCostReport::where('depart_id', $depart->id)->count());
        $this->assertNull($operation->fresh()->reference_id);
    }

    public function test_it_aborts_and_changes_nothing_when_an_operation_is_over_allocated(): void
    {
        $firstDepart = $this->createDepart();
        $secondDepart = $this->createDepart();
        $operation = $this->createLegacyOperation(100_000, '2026-10-21 10:00:00');

        $this->assertSame(1, $this->runCommand([
            ['operationId' => $operation->id, 'departId' => $firstDepart->id, 'amount' => 60_000],
            ['operationId' => $operation->id, 'departId' => $secondDepart->id, 'amount' => 60_000],
        ], apply: true));

        $this->assertSame(0, DepartCostReport::whereIn('depart_id', [$firstDepart->id, $secondDepart->id])->count());
        $this->assertSame(100_000, $operation->fresh()->amount);
    }

    public function test_it_aborts_for_a_depart_that_already_has_a_cost_report_or_an_operation_already_attached(): void
    {
        $depart = $this->createDepart();
        $depart->costReport()->create();
        $operation = $this->createLegacyOperation(100_000, '2026-10-21 10:00:00');

        $this->assertSame(1, $this->runCommand([['operationId' => $operation->id, 'departId' => $depart->id, 'amount' => 100_000]], apply: true));
        $this->assertSame(100_000, $operation->fresh()->amount);
        $this->assertNull($operation->fresh()->reference_id);
    }
}
