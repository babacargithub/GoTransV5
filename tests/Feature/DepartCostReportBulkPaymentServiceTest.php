<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\ExpenseTypeCode;
use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Caisse;
use App\Models\Depart;
use App\Models\ExpenseType;
use App\Models\Trajet;
use App\Services\AccountService;
use App\Services\DepartCostReportPaymentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use InvalidArgumentException;
use Tests\TestCase;

class DepartCostReportBulkPaymentServiceTest extends TestCase
{
    use DatabaseTransactions;

    private function createDepartWithRentalOwing(int $rentalFee): Depart
    {
        $depart = Depart::create([
            'name' => 'DEPART BULK SERVICE '.uniqid(),
            'date' => '2032-01-10 08:00:00',
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
     * @return array{0: Caisse, 1: Account}
     */
    private function createFundedCaisseAndAccount(int $balance): array
    {
        $caisse = Caisse::create(['name' => 'Caisse svc '.uniqid(), 'balance' => 0]);
        $account = Account::create(['name' => 'Compte svc '.uniqid(), 'account_type' => AccountType::Management, 'balance' => 0, 'is_active' => true]);
        app(AccountService::class)->processEntreeDeCaisse($caisse, $account, $balance, 'Fonds de test');

        return [$caisse->fresh(), $account->fresh()];
    }

    public function test_it_refuses_an_empty_selection_a_duplicated_depart_and_a_depart_without_cost_report(): void
    {
        $service = app(DepartCostReportPaymentService::class);
        [$caisse, $account] = $this->createFundedCaisseAndAccount(50000);
        $depart = $this->createDepartWithRentalOwing(5000);
        $departWithoutCostReport = Depart::create([
            'name' => 'DEPART SANS BILAN '.uniqid(),
            'date' => '2032-01-11 08:00:00',
            'trajet_id' => $depart->trajet_id,
            'closed' => false,
            'locked' => false,
            'canceled' => false,
        ]);

        foreach ([[], [$depart->id, $depart->id], [$depart->id, $departWithoutCostReport->id]] as $invalidDepartIds) {
            try {
                $service->recordBulkBusRentalPayments($invalidDepartIds, [$caisse->id => 5000], [$account->id => 5000]);
                $this->fail('Expected an InvalidArgumentException.');
            } catch (InvalidArgumentException) {
                $this->assertSame(50000, $caisse->fresh()->balance);
            }
        }
    }

    public function test_it_refuses_totals_that_differ_from_what_is_owed_and_pays_nothing(): void
    {
        $service = app(DepartCostReportPaymentService::class);
        [$caisse, $account] = $this->createFundedCaisseAndAccount(50000);
        $depart = $this->createDepartWithRentalOwing(5000);

        $this->expectException(InvalidArgumentException::class);

        try {
            $service->recordBulkBusRentalPayments([$depart->id], [$caisse->id => 4000], [$account->id => 5000]);
        } finally {
            $this->assertSame(50000, $caisse->fresh()->balance);
            $this->assertSame(5000, $depart->costReport->busRentals()->first()->remainingAmountToPay());
        }
    }

    public function test_bus_rental_payments_are_classified_under_the_location_de_bus_expense_type(): void
    {
        [$caisse, $account] = $this->createFundedCaisseAndAccount(50000);
        $depart = $this->createDepartWithRentalOwing(5000);

        app(DepartCostReportPaymentService::class)->recordBulkBusRentalPayments([$depart->id], [$caisse->id => 5000], [$account->id => 5000]);

        $debit = AccountTransaction::where('account_id', $account->id)->where('transaction_type', 'DEBIT')->firstOrFail();
        $this->assertSame(ExpenseType::idForCode(ExpenseTypeCode::LocationDeBus), $debit->expense_type_id);
    }

    public function test_it_cannot_pay_the_same_departs_twice(): void
    {
        $service = app(DepartCostReportPaymentService::class);
        [$caisse, $account] = $this->createFundedCaisseAndAccount(50000);
        $depart = $this->createDepartWithRentalOwing(5000);

        $service->recordBulkBusRentalPayments([$depart->id], [$caisse->id => 5000], [$account->id => 5000]);

        $this->expectException(InvalidArgumentException::class);

        try {
            $service->recordBulkBusRentalPayments([$depart->id], [$caisse->id => 5000], [$account->id => 5000]);
        } finally {
            $this->assertSame(45000, $caisse->fresh()->balance);
        }
    }
}
