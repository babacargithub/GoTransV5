<?php

namespace Tests\Feature\BackOffice;

use App\Enums\AccountType;
use App\Livewire\BackOffice\DepartCostReportList;
use App\Models\Account;
use App\Models\Bus;
use App\Models\Caisse;
use App\Models\Customer;
use App\Models\Depart;
use App\Models\Seat;
use App\Models\Ticket;
use App\Models\Trajet;
use App\Services\AccountService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class DepartCostReportListPageTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.back_office_departs_per_page' => 1000]);
    }

    /**
     * Creates a départ on the given date with one rented bus that sold one ticket, and its saved cost report.
     * Dates are far in the future so that these départs are the only ones in the tested range.
     */
    private function createDepartWithCostReport(string $departDate, int $ticketPrice, int $rentalFee): Depart
    {
        $trajet = Trajet::query()->has('pointDeps')->has('destinations')->firstOrFail();

        $depart = Depart::create([
            'name' => 'DEPART COST LIST '.uniqid(),
            'date' => $departDate.' 08:00:00',
            'trajet_id' => $trajet->id,
            'closed' => false,
            'locked' => false,
            'canceled' => false,
        ]);

        $bus = $depart->buses()->create([
            'name' => 'Bus Cost List',
            'nombre_place' => 57,
            'ticket_price' => $ticketPrice,
            'gp_ticket_price' => 6000,
            'closed' => false,
        ]);

        $ticket = new Ticket;
        $ticket->forceFill([
            'number' => random_int(1_000_000, 9_999_999_999),
            'price' => $ticketPrice,
            'payment_method' => 'wave',
            'used' => true,
            'soldBy' => 'agence test',
            'soldAt' => now(),
            'expiryDate' => now()->addDays(30),
        ])->save();

        $bus->bookings()->create([
            'customer_id' => Customer::create([
                'prenom' => 'awa',
                'nom' => 'ndiaye',
                'phone_number' => 770000000 + random_int(1, 9999999),
            ])->id,
            'depart_id' => $depart->id,
            'point_dep_id' => $trajet->pointDeps()->firstOrFail()->id,
            'destination_id' => $trajet->destinations()->firstOrFail()->id,
            'seat_id' => $bus->seats()->create([
                'seat_id' => Seat::query()->firstOrFail()->id,
                'booked' => true,
                'price' => $ticketPrice,
            ])->id,
            'ticket_id' => $ticket->id,
            'paye' => true,
        ]);

        $costReport = $depart->costReport()->create();
        $costReport->busRentals()->create(['bus_id' => $bus->id, 'bus_owner_name' => 'Mamadou Sow', 'rental_fee' => $rentalFee]);

        return $depart->fresh();
    }

    public function test_the_page_is_restricted_to_full_access_users_and_linked_from_the_finances_menu(): void
    {
        $this->actingAs($this->createUserWithPermissions([]))
            ->get(route('back-office.cost-reports.index'))
            ->assertForbidden();

        $fullAccessUser = $this->createUserWithFullAccess();

        $this->actingAs($fullAccessUser)
            ->get(route('back-office.cost-reports.index'))
            ->assertOk()
            ->assertSee('Bilans');

        $this->actingAs($fullAccessUser)
            ->get(route('back-office.departs.index'))
            ->assertSee(route('back-office.cost-reports.index'), false);
    }

    public function test_it_lists_each_depart_with_its_sales_rental_commissions_and_profit(): void
    {
        $depart = $this->createDepartWithCostReport('2031-03-10', 12000, 7000);

        $component = Livewire::actingAs($this->createUserWithFullAccess())
            ->test(DepartCostReportList::class)
            ->set('startDate', '2031-03-01')
            ->set('endDate', '2031-03-31');

        $this->assertSame([[
            'departId' => $depart->id,
            'departName' => $depart->identifier(with_trajet_prefix: true),
            'departDate' => $depart->date->isoFormat('ddd D MMM YYYY'),
            'busesCount' => 1,
            'ticketSales' => 12000,
            'busRentalFees' => 7000,
            'remainingToPay' => 7000,
            'busRentalPaymentStatus' => 'unpaid',
            'commissions' => 0,
            'profit' => 5000,
        ]], $component->instance()->costReportRows);

        $component->assertSee($depart->identifier(with_trajet_prefix: true));
    }

    public function test_each_row_shows_whether_its_bus_rental_is_unpaid_partially_paid_or_paid_in_caisse(): void
    {
        $unpaidDepart = $this->createDepartWithCostReport('2031-04-10', 12000, 7000);
        $partiallyPaidDepart = $this->createDepartWithCostReport('2031-04-11', 12000, 7000);
        $paidDepart = $this->createDepartWithCostReport('2031-04-12', 12000, 7000);
        $partiallyPaidDepart->costReport->busRentals()->update(['paid_amount' => 3000]);
        $paidDepart->costReport->busRentals()->update(['paid_amount' => 7000]);

        $component = Livewire::actingAs($this->createUserWithFullAccess())
            ->test(DepartCostReportList::class)
            ->set('startDate', '2031-04-01')
            ->set('endDate', '2031-04-30');

        $statusByDepartId = collect($component->instance()->costReportRows)->pluck('busRentalPaymentStatus', 'departId');

        $this->assertSame('unpaid', $statusByDepartId[$unpaidDepart->id]);
        $this->assertSame('partial', $statusByDepartId[$partiallyPaidDepart->id]);
        $this->assertSame('paid', $statusByDepartId[$paidDepart->id]);

        $component
            ->assertSeeHtml('data-test="bus-rental-unpaid"')
            ->assertSeeHtml('data-test="bus-rental-partial"')
            ->assertSeeHtml('data-test="bus-rental-paid"');
    }

    public function test_the_summary_card_totals_the_filtered_cost_reports(): void
    {
        $this->createDepartWithCostReport('2031-03-10', 12000, 7000);
        $this->createDepartWithCostReport('2031-03-20', 10000, 4000);
        $this->createDepartWithCostReport('2031-05-10', 99000, 50000);

        $component = Livewire::actingAs($this->createUserWithFullAccess())
            ->test(DepartCostReportList::class)
            ->set('startDate', '2031-03-01')
            ->set('endDate', '2031-03-31');

        $this->assertSame([
            'departsCount' => 2,
            'busesCount' => 2,
            'ticketSales' => 22000,
            'commissions' => 0,
            'expenses' => 11000,
            'netProfit' => 11000,
        ], $component->instance()->summary);
    }

    public function test_the_date_filters_narrow_the_list_and_can_be_reset(): void
    {
        $marchDepart = $this->createDepartWithCostReport('2031-03-10', 12000, 7000);
        $mayDepart = $this->createDepartWithCostReport('2031-05-10', 10000, 4000);

        $component = Livewire::actingAs($this->createUserWithFullAccess())
            ->test(DepartCostReportList::class)
            ->set('startDate', '2031-05-01')
            ->set('endDate', '2031-05-31');

        $this->assertSame([$mayDepart->id], array_column($component->instance()->costReportRows, 'departId'));

        $component->call('resetFilters')
            ->assertSet('startDate', now()->startOfMonth()->toDateString())
            ->assertSet('endDate', now()->endOfMonth()->toDateString());

        $departIdsAfterReset = array_column($component->instance()->costReportRows, 'departId');
        $this->assertNotContains($marchDepart->id, $departIdsAfterReset);
        $this->assertNotContains($mayDepart->id, $departIdsAfterReset);
    }

    public function test_the_default_range_is_the_current_month(): void
    {
        $departThisMonth = $this->createDepartWithCostReport(now()->startOfMonth()->addDays(3)->toDateString(), 12000, 7000);
        $departLastMonth = $this->createDepartWithCostReport(now()->subMonthNoOverflow()->startOfMonth()->addDays(3)->toDateString(), 12000, 7000);

        $component = Livewire::actingAs($this->createUserWithFullAccess())
            ->test(DepartCostReportList::class)
            ->assertSet('startDate', now()->startOfMonth()->toDateString())
            ->assertSet('endDate', now()->endOfMonth()->toDateString());

        $listedDepartIds = array_column($component->instance()->costReportRows, 'departId');
        $this->assertContains($departThisMonth->id, $listedDepartIds);
        $this->assertNotContains($departLastMonth->id, $listedDepartIds);
    }

    /**
     * @return array{0: Caisse, 1: Account}
     */
    private function createFundedCaisseAndAccount(int $balance): array
    {
        $caisse = Caisse::create(['name' => 'Caisse bulk '.uniqid(), 'balance' => 0]);
        $account = Account::create(['name' => 'Compte bulk '.uniqid(), 'account_type' => AccountType::Management, 'balance' => 0, 'is_active' => true]);
        app(AccountService::class)->processEntreeDeCaisse($caisse, $account, $balance, 'Fonds de test');

        return [$caisse->fresh(), $account->fresh()];
    }

    public function test_bulk_cash_out_records_one_distinct_operation_per_depart(): void
    {
        $firstDepart = $this->createDepartWithCostReport('2031-06-10', 12000, 7000);
        $secondDepart = $this->createDepartWithCostReport('2031-06-11', 10000, 4000);
        [$caisse, $account] = $this->createFundedCaisseAndAccount(100000);
        $user = $this->createUserWithFullAccess();

        Livewire::actingAs($user)
            ->test(DepartCostReportList::class)
            ->set('startDate', '2031-06-01')
            ->set('endDate', '2031-06-30')
            ->call('startSelecting')
            ->set('selectedDepartIds', [(string) $firstDepart->id, (string) $secondDepart->id])
            ->call('openCashOutModal')
            ->assertSet('showCashOutModal', true)
            ->assertSet('cashOutCaisseRows.0.amount', '11000')
            ->set('cashOutCaisseRows.0.caisseId', (string) $caisse->id)
            ->set('cashOutAccountRows.0.accountId', (string) $account->id)
            ->call('saveCashOut')
            ->assertHasNoErrors()
            ->assertSet('isSelecting', false)
            ->assertSet('selectedDepartIds', []);

        $this->assertSame(89000, $caisse->fresh()->balance);
        $this->assertSame(89000, $account->fresh()->balance);

        $debits = $account->transactions()->where('reference_type', 'DEPART_COST_REPORT')->orderBy('id')->get();
        $this->assertSame([7000, 4000], $debits->map(fn ($debit): int => (int) $debit->amount)->all());
        $this->assertSame(
            [$firstDepart->costReport->id, $secondDepart->costReport->id],
            $debits->pluck('reference_id')->all(),
        );
        $this->assertSame(
            ['Location de bus '.$firstDepart->identifier(with_trajet_prefix: true), 'Location de bus '.$secondDepart->identifier(with_trajet_prefix: true)],
            $debits->pluck('label')->all(),
        );
        $this->assertSame(0, $firstDepart->costReport->busRentals()->first()->remainingAmountToPay());
        $this->assertSame(0, $secondDepart->costReport->busRentals()->first()->remainingAmountToPay());
    }

    public function test_bulk_cash_out_dispatches_several_caisses_across_the_departs(): void
    {
        $firstDepart = $this->createDepartWithCostReport('2031-07-10', 12000, 7000);
        $secondDepart = $this->createDepartWithCostReport('2031-07-11', 10000, 4000);
        [$firstCaisse, $firstAccount] = $this->createFundedCaisseAndAccount(50000);
        [$secondCaisse, $secondAccount] = $this->createFundedCaisseAndAccount(50000);

        Livewire::actingAs($this->createUserWithFullAccess())
            ->test(DepartCostReportList::class)
            ->set('startDate', '2031-07-01')
            ->set('endDate', '2031-07-31')
            ->call('startSelecting')
            ->set('selectedDepartIds', [$firstDepart->id, $secondDepart->id])
            ->call('openCashOutModal')
            ->call('addCashOutCaisseRow')
            ->set('cashOutCaisseRows.0.caisseId', (string) $firstCaisse->id)
            ->set('cashOutCaisseRows.0.amount', '9000')
            ->set('cashOutCaisseRows.1.caisseId', (string) $secondCaisse->id)
            ->set('cashOutCaisseRows.1.amount', '2000')
            ->set('cashOutAccountRows.0.accountId', (string) $firstAccount->id)
            ->call('saveCashOut')
            ->assertHasNoErrors();

        $this->assertSame(41000, $firstCaisse->fresh()->balance);
        $this->assertSame(48000, $secondCaisse->fresh()->balance);
        $this->assertSame(39000, $firstAccount->fresh()->balance);
        $this->assertSame(50000, $secondAccount->fresh()->balance);
    }

    public function test_bulk_cash_out_is_all_or_nothing_when_a_caisse_balance_is_insufficient(): void
    {
        $firstDepart = $this->createDepartWithCostReport('2031-08-10', 12000, 7000);
        $secondDepart = $this->createDepartWithCostReport('2031-08-11', 10000, 4000);
        [$caisse, $account] = $this->createFundedCaisseAndAccount(8000);

        Livewire::actingAs($this->createUserWithFullAccess())
            ->test(DepartCostReportList::class)
            ->set('startDate', '2031-08-01')
            ->set('endDate', '2031-08-31')
            ->call('startSelecting')
            ->set('selectedDepartIds', [$firstDepart->id, $secondDepart->id])
            ->call('openCashOutModal')
            ->set('cashOutCaisseRows.0.caisseId', (string) $caisse->id)
            ->set('cashOutAccountRows.0.accountId', (string) $account->id)
            ->call('saveCashOut')
            ->assertSet('showCashOutModal', true);

        $this->assertSame(8000, $caisse->fresh()->balance);
        $this->assertSame(7000, $firstDepart->costReport->busRentals()->first()->remainingAmountToPay());
        $this->assertSame(4000, $secondDepart->costReport->busRentals()->first()->remainingAmountToPay());
    }

    public function test_bulk_cash_out_ignores_departs_already_fully_paid_and_refuses_users_without_full_access(): void
    {
        $paidDepart = $this->createDepartWithCostReport('2031-09-10', 12000, 7000);
        $paidDepart->costReport->busRentals()->update(['paid_amount' => 7000]);

        Livewire::actingAs($this->createUserWithFullAccess())
            ->test(DepartCostReportList::class)
            ->set('startDate', '2031-09-01')
            ->set('endDate', '2031-09-30')
            ->call('startSelecting')
            ->set('selectedDepartIds', [$paidDepart->id])
            ->call('openCashOutModal')
            ->assertSet('showCashOutModal', false)
            ->assertSet('flashErrorMessage', 'Sélectionnez au moins un départ avec un reste à payer.');

        Livewire::actingAs($this->createUserWithPermissions([]))
            ->test(DepartCostReportList::class)
            ->assertForbidden();
    }
}
