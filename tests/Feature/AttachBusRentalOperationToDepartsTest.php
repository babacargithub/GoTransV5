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
use App\Models\ExpenseType;
use App\Models\Trajet;
use App\Services\AccountService;
use App\Services\DepartCostReportPaymentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AttachBusRentalOperationToDepartsTest extends TestCase
{
    use DatabaseTransactions;

    private const OPERATION_DATE = '2026-11-03 07:13:30';

    private Caisse $caisse;

    private Account $account;

    private AccountTransaction $legacyOperation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->caisse = Caisse::create(['name' => 'Caisse attach '.uniqid(), 'balance' => 0]);
        $this->account = Account::create(['name' => 'Compte attach '.uniqid(), 'account_type' => AccountType::Management, 'balance' => 0, 'is_active' => true]);
        $accountService = app(AccountService::class);
        $accountService->processEntreeDeCaisse($this->caisse, $this->account, 1_000_000, 'Fonds de test');
        $accountService->processSortieDeCaisse(
            $this->caisse->fresh(), 621_000, 'Location de bus test attach '.uniqid(), [$this->account->id],
            categoryOverride: AccountTransactionCategory::Expense,
            expenseTypeId: ExpenseType::idForCode(ExpenseTypeCode::LocationDeBus),
        );

        $this->legacyOperation = AccountTransaction::where('account_id', $this->account->id)->where('amount', 621_000)->firstOrFail();
        $this->legacyOperation->forceFill(['created_at' => self::OPERATION_DATE])->save();
        CaisseTransaction::where('caisse_id', $this->caisse->id)->where('amount', 621_000)->update(['created_at' => self::OPERATION_DATE]);
    }

    private function createDepartWithRentalOwing(int $rentalFee): Depart
    {
        $depart = Depart::create([
            'name' => 'DEPART ATTACH '.uniqid(),
            'date' => '2026-11-01 08:00:00',
            'trajet_id' => Trajet::query()->has('pointDeps')->has('destinations')->firstOrFail()->id,
            'closed' => false,
            'locked' => false,
            'canceled' => false,
        ]);
        $bus = $depart->buses()->create(['name' => 'Bus', 'nombre_place' => 57, 'ticket_price' => 10000, 'gp_ticket_price' => 6000, 'closed' => false]);
        $depart->costReport()->create()->busRentals()->create(['bus_id' => $bus->id, 'bus_owner_name' => 'Owner', 'rental_fee' => $rentalFee]);

        return $depart;
    }

    /**
     * @param  array<int, Depart>  $departs
     * @return array<string, mixed>
     */
    private function commandArguments(array $departs, bool $apply): array
    {
        return array_filter([
            'accountTransactionId' => $this->legacyOperation->id,
            '--depart' => array_map(fn (Depart $depart): int => $depart->id, $departs),
            '--residual-label' => 'Location de bus du samedi 31 oct',
            '--apply' => $apply,
        ]);
    }

    public function test_unpaid_departs_get_their_own_operation_dated_like_the_original_and_the_original_keeps_the_rest(): void
    {
        $firstDepart = $this->createDepartWithRentalOwing(117_600);
        $secondDepart = $this->createDepartWithRentalOwing(101_000);
        $balanceBefore = $this->account->fresh()->balance;

        $this->artisan('ledger:attach-bus-rental-operation', $this->commandArguments([$firstDepart, $secondDepart], apply: true))->assertSuccessful();

        $this->assertSame(621_000 - 218_600, $this->legacyOperation->fresh()->amount);
        $this->assertSame('Location de bus du samedi 31 oct', $this->legacyOperation->fresh()->label);

        foreach ([[$firstDepart, 117_600], [$secondDepart, 101_000]] as [$depart, $fee]) {
            $operation = AccountTransaction::where('reference_type', DepartCostReportPaymentService::ACCOUNT_REFERENCE_TYPE)
                ->where('reference_id', $depart->costReport->id)->sole();
            $this->assertSame($fee, $operation->amount);
            $this->assertSame(self::OPERATION_DATE, $operation->created_at->format('Y-m-d H:i:s'));
            $this->assertSame(ExpenseType::idForCode(ExpenseTypeCode::LocationDeBus), $operation->expense_type_id);
            $this->assertSame(0, $depart->costReport->busRentals()->first()->remainingAmountToPay());
            $this->assertSame(1, CaisseTransaction::where('label', $operation->label)->where('amount', $fee)->where('created_at', self::OPERATION_DATE)->count());
        }

        $this->assertSame($balanceBefore, $this->account->fresh()->balance);
        $this->assertSame($balanceBefore, $this->caisse->fresh()->balance);
        $this->assertTrue(app(AccountService::class)->isGlobalInvariantSatisfied());
    }

    public function test_a_depart_already_paid_by_the_bulk_payment_reuses_that_operation_and_the_total_out_stays_the_amount_spent(): void
    {
        $depart = $this->createDepartWithRentalOwing(117_600);
        app(DepartCostReportPaymentService::class)->recordBulkBusRentalPayments([$depart->id], [$this->caisse->id => 117_600], [$this->account->id => 117_600]);
        $balanceAfterTestPayment = $this->account->fresh()->balance;

        $this->artisan('ledger:attach-bus-rental-operation', $this->commandArguments([$depart], apply: true))->assertSuccessful();

        $operation = AccountTransaction::where('reference_type', DepartCostReportPaymentService::ACCOUNT_REFERENCE_TYPE)
            ->where('reference_id', $depart->costReport->id)->sole();
        $this->assertSame(self::OPERATION_DATE, $operation->created_at->format('Y-m-d H:i:s'));
        $this->assertSame(621_000 - 117_600, $this->legacyOperation->fresh()->amount);
        $this->assertSame($balanceAfterTestPayment + 117_600, $this->account->fresh()->balance);
        $this->assertSame($this->account->fresh()->balance, $this->caisse->fresh()->balance);
        $this->assertTrue(app(AccountService::class)->isGlobalInvariantSatisfied());
    }

    public function test_the_part_not_attached_to_departs_can_be_booked_on_an_earlier_date_in_the_accounts_only(): void
    {
        $depart = $this->createDepartWithRentalOwing(117_600);

        $this->artisan('ledger:attach-bus-rental-operation', $this->commandArguments([$depart], apply: true) + ['--residual-date' => '2026-10-31'])->assertSuccessful();

        $this->assertSame('2026-10-31 23:59:59', $this->legacyOperation->fresh()->created_at->format('Y-m-d H:i:s'));
        $this->assertSame(
            self::OPERATION_DATE,
            CaisseTransaction::where('caisse_id', $this->caisse->id)->where('amount', 621_000 - 117_600)->sole()->created_at->format('Y-m-d H:i:s'),
        );
        $this->assertTrue(app(AccountService::class)->isGlobalInvariantSatisfied());
    }

    public function test_it_only_simulates_without_the_apply_option(): void
    {
        $depart = $this->createDepartWithRentalOwing(117_600);

        $this->artisan('ledger:attach-bus-rental-operation', $this->commandArguments([$depart], apply: false))->assertSuccessful();

        $this->assertSame(621_000, $this->legacyOperation->fresh()->amount);
        $this->assertSame(117_600, $depart->costReport->busRentals()->first()->remainingAmountToPay());
        $this->assertSame(0, AccountTransaction::where('reference_id', $depart->costReport->id)->where('reference_type', DepartCostReportPaymentService::ACCOUNT_REFERENCE_TYPE)->count());
    }

    public function test_it_aborts_and_changes_nothing_when_the_departs_exceed_the_original_operation(): void
    {
        $firstDepart = $this->createDepartWithRentalOwing(400_000);
        $secondDepart = $this->createDepartWithRentalOwing(300_000);

        $this->artisan('ledger:attach-bus-rental-operation', $this->commandArguments([$firstDepart, $secondDepart], apply: true))->assertFailed();

        $this->assertSame(621_000, $this->legacyOperation->fresh()->amount);
        $this->assertSame(400_000, $firstDepart->costReport->busRentals()->first()->remainingAmountToPay());
        $this->assertSame(0, AccountTransaction::where('reference_type', DepartCostReportPaymentService::ACCOUNT_REFERENCE_TYPE)->whereIn('reference_id', [$firstDepart->costReport->id, $secondDepart->costReport->id])->count());
    }

    public function test_it_aborts_on_a_partially_paid_depart(): void
    {
        $depart = $this->createDepartWithRentalOwing(117_600);
        $depart->costReport->busRentals()->update(['paid_amount' => 50_000]);

        $this->artisan('ledger:attach-bus-rental-operation', $this->commandArguments([$depart], apply: true))->assertFailed();

        $this->assertSame(621_000, $this->legacyOperation->fresh()->amount);
    }
}
