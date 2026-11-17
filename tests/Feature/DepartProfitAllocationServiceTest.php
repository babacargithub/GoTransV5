<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Bus;
use App\Models\Caisse;
use App\Models\Customer;
use App\Models\Depart;
use App\Models\DepartCostReport;
use App\Models\MonthlyExpenseBudget;
use App\Models\Seat;
use App\Models\Ticket;
use App\Models\Trajet;
use App\Services\AccountService;
use App\Services\DepartProfitAllocationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DepartProfitAllocationServiceTest extends TestCase
{
    use DatabaseTransactions;

    private const PAST_MONTH_START = '2019-05-01';

    private DepartProfitAllocationService $departProfitAllocationService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->departProfitAllocationService = app(DepartProfitAllocationService::class);
    }

    private function planMonthlyCosts(int $amount): void
    {
        MonthlyExpenseBudget::factory()->create(['month' => self::PAST_MONTH_START, 'amount' => $amount]);
    }

    /**
     * Creates a départ with one bus, ticketSales sold on it and a cost report renting that bus for rentalFee.
     */
    private function createCostReport(string $departDate, int $ticketSales, int $rentalFee): DepartCostReport
    {
        $trajet = Trajet::query()->has('pointDeps')->has('destinations')->firstOrFail();
        $depart = Depart::create([
            'name' => 'DEPART PROFIT '.uniqid(),
            'date' => $departDate,
            'trajet_id' => $trajet->id,
            'closed' => false,
            'locked' => false,
            'canceled' => false,
        ]);
        $bus = $depart->buses()->create(['name' => 'Bus Profit', 'nombre_place' => 57, 'ticket_price' => 3550, 'gp_ticket_price' => 6000, 'closed' => false]);

        if ($ticketSales > 0) {
            $this->sellTicketOnBus($bus, $trajet, $ticketSales);
        }

        $costReport = $depart->costReport()->create();
        $costReport->busRentals()->create(['bus_id' => $bus->id, 'bus_owner_name' => 'Owner', 'rental_fee' => $rentalFee]);

        return $costReport;
    }

    private function sellTicketOnBus(Bus $bus, Trajet $trajet, int $ticketPrice): Ticket
    {
        $customer = Customer::create(['prenom' => 'awa', 'nom' => 'ndiaye', 'phone_number' => 770000000 + random_int(1, 9999999)]);

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
            'customer_id' => $customer->id,
            'depart_id' => $bus->depart_id,
            'point_dep_id' => $trajet->pointDeps()->firstOrFail()->id,
            'destination_id' => $trajet->destinations()->firstOrFail()->id,
            'seat_id' => $bus->seats()->create(['seat_id' => Seat::query()->inRandomOrder()->firstOrFail()->id, 'booked' => true, 'price' => $ticketPrice])->id,
            'ticket_id' => $ticket->id,
            'paye' => true,
        ]);

        return $ticket;
    }

    private function balanceOf(string $accountName): int
    {
        return Account::query()->where('name', $accountName)->firstOrFail()->balance;
    }

    private function fundTicketSalesAccount(int $amount): void
    {
        $caisse = Caisse::create(['name' => 'Caisse profit '.uniqid(), 'balance' => 0]);
        app(AccountService::class)->processEntreeDeCaisse($caisse, app(AccountService::class)->getOrCreateTicketSalesAccount(), $amount, 'Fonds de test');
    }

    public function test_the_whole_profit_goes_to_charges_while_the_monthly_planned_costs_are_not_reached(): void
    {
        $this->planMonthlyCosts(500000);
        $this->fundTicketSalesAccount(1000000);
        $costReport = $this->createCostReport('2019-05-10 08:00:00', ticketSales: 300000, rentalFee: 100000);
        $chargesBefore = $this->balanceOf('Charges');
        $benefitsBefore = $this->balanceOf('Bénéfices');
        $ticketSalesBefore = $this->balanceOf('Ventes de billets');

        $allocation = $this->departProfitAllocationService->allocate($costReport);

        $this->assertSame(['charges' => 200000, 'benefits' => 0], $allocation);
        $this->assertSame($chargesBefore + 200000, $this->balanceOf('Charges'));
        $this->assertSame($benefitsBefore, $this->balanceOf('Bénéfices'));
        $this->assertSame($ticketSalesBefore - 200000, $this->balanceOf('Ventes de billets'));
    }

    public function test_the_profit_beyond_the_planned_costs_goes_to_benefits_and_later_departs_only_feed_benefits(): void
    {
        $this->planMonthlyCosts(250000);
        $this->fundTicketSalesAccount(1000000);
        $firstCostReport = $this->createCostReport('2019-05-10 08:00:00', ticketSales: 300000, rentalFee: 100000);
        $secondCostReport = $this->createCostReport('2019-05-12 08:00:00', ticketSales: 80000, rentalFee: 30000);

        $firstAllocation = $this->departProfitAllocationService->allocate($firstCostReport);
        $secondAllocation = $this->departProfitAllocationService->allocate($secondCostReport);

        $this->assertSame(['charges' => 200000, 'benefits' => 0], $firstAllocation);
        $this->assertSame(['charges' => 50000, 'benefits' => 0], $secondAllocation);

        $thirdCostReport = $this->createCostReport('2019-05-15 08:00:00', ticketSales: 90000, rentalFee: 40000);

        $this->assertSame(['charges' => 0, 'benefits' => 50000], $this->departProfitAllocationService->allocate($thirdCostReport));
    }

    public function test_allocating_again_changes_nothing_and_a_lowered_profit_gives_money_back_to_ticket_sales(): void
    {
        $this->planMonthlyCosts(150000);
        $this->fundTicketSalesAccount(1000000);
        $costReport = $this->createCostReport('2019-05-10 08:00:00', ticketSales: 300000, rentalFee: 100000);
        $this->departProfitAllocationService->allocate($costReport);
        $transactionsCountAfterFirstAllocation = Account::query()->where('name', 'Charges')->firstOrFail()->transactions()->count();
        $chargesBalanceAfterFirstAllocation = $this->balanceOf('Charges');
        $benefitsBalanceAfterFirstAllocation = $this->balanceOf('Bénéfices');

        $this->departProfitAllocationService->allocate($costReport);

        $this->assertSame($transactionsCountAfterFirstAllocation, Account::query()->where('name', 'Charges')->firstOrFail()->transactions()->count());

        $costReport->busRentals()->update(['rental_fee' => 250000]);
        $allocationAfterRentalIncrease = $this->departProfitAllocationService->allocate($costReport->fresh());

        $this->assertSame(['charges' => 50000, 'benefits' => 0], $allocationAfterRentalIncrease);
        $this->assertSame($chargesBalanceAfterFirstAllocation - 100000, $this->balanceOf('Charges'));
        $this->assertSame($benefitsBalanceAfterFirstAllocation - 50000, $this->balanceOf('Bénéfices'));
    }

    public function test_nothing_is_allocated_before_the_depart_has_left_or_when_it_made_a_loss(): void
    {
        $this->planMonthlyCosts(500000);
        $upcomingCostReport = $this->createCostReport(now()->addDays(3)->toDateTimeString(), ticketSales: 300000, rentalFee: 100000);
        $lossMakingCostReport = $this->createCostReport('2019-05-10 08:00:00', ticketSales: 50000, rentalFee: 100000);
        $chargesBefore = $this->balanceOf('Charges');

        $this->assertNull($this->departProfitAllocationService->allocate($upcomingCostReport));
        $this->assertSame(['charges' => 0, 'benefits' => 0], $this->departProfitAllocationService->allocate($lossMakingCostReport));
        $this->assertSame($chargesBefore, $this->balanceOf('Charges'));
    }
}
