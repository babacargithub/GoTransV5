<?php

namespace Tests\Feature\BackOffice;

use App\Livewire\BackOffice\DepartCostReportList;
use App\Models\Bus;
use App\Models\Customer;
use App\Models\Depart;
use App\Models\Seat;
use App\Models\Ticket;
use App\Models\Trajet;
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
            'commissions' => 0,
            'profit' => 5000,
        ]], $component->instance()->costReportRows);

        $component->assertSee($depart->identifier(with_trajet_prefix: true));
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
}
