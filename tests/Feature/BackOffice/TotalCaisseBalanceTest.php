<?php

namespace Tests\Feature\BackOffice;

use App\Livewire\BackOffice\TotalCaisseBalance;
use App\Models\Bus;
use App\Models\Customer;
use App\Models\Depart;
use App\Models\Seat;
use App\Models\Ticket;
use App\Models\Trajet;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class TotalCaisseBalanceTest extends TestCase
{
    use DatabaseTransactions;

    private function createDepartWithBus(string $departDate): Bus
    {
        $trajet = Trajet::query()->has('pointDeps')->has('destinations')->firstOrFail();
        $depart = Depart::create([
            'name' => 'CAISSE WIDGET '.uniqid(),
            'date' => $departDate,
            'trajet_id' => $trajet->id,
            'closed' => false,
            'locked' => false,
            'canceled' => false,
        ]);

        return $depart->buses()->create([
            'name' => 'Bus Caisse Widget',
            'nombre_place' => 57,
            'ticket_price' => 3550,
            'gp_ticket_price' => 6000,
            'closed' => false,
        ]);
    }

    private function sellTicketOnBus(Bus $bus, int $ticketPrice): void
    {
        $trajet = Trajet::query()->has('pointDeps')->has('destinations')->firstOrFail();
        $customer = Customer::create([
            'prenom' => 'awa',
            'nom' => 'ndiaye',
            'phone_number' => 770000000 + random_int(1, 9999999),
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
            'customer_id' => $customer->id,
            'depart_id' => $bus->depart_id,
            'point_dep_id' => $trajet->pointDeps()->firstOrFail()->id,
            'destination_id' => $trajet->destinations()->firstOrFail()->id,
            'seat_id' => $bus->seats()->create([
                'seat_id' => Seat::query()->inRandomOrder()->firstOrFail()->id,
                'booked' => true,
                'price' => $ticketPrice,
            ])->id,
            'ticket_id' => $ticket->id,
            'paye' => true,
        ]);
    }

    public function test_upcoming_ticket_sales_only_count_departs_that_have_not_left_and_cashed_balance_subtracts_them(): void
    {
        $initialUpcomingTicketSales = Livewire::test(TotalCaisseBalance::class)->instance()->upcomingTicketSales();

        $this->sellTicketOnBus($this->createDepartWithBus(now()->addDays(2)->toDateTimeString()), 3550);
        $this->sellTicketOnBus($this->createDepartWithBus(now()->subDays(2)->toDateTimeString()), 9000);

        $component = Livewire::test(TotalCaisseBalance::class);

        $this->assertSame($initialUpcomingTicketSales + 3550, $component->instance()->upcomingTicketSales());
        $this->assertSame(
            $component->instance()->totalBalance() - $component->instance()->upcomingTicketSales(),
            $component->instance()->cashedBalance(),
        );
    }
}
