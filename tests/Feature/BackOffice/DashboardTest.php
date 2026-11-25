<?php

namespace Tests\Feature\BackOffice;

use App\Data\Dashboard\DashboardDTO;
use App\Models\Ambassador;
use App\Models\AmbassadorEarning;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Depart;
use App\Models\Destination;
use App\Models\PointDep;
use App\Models\Trajet;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use DatabaseTransactions;

    private function createTrajetWithDepartAndBus(): array
    {
        $trajet = Trajet::create(['name' => 'Trajet '.uniqid(), 'departure_city' => 'DAKAR', 'arrival_city' => 'SAINT-LOUIS']);
        $depart = Depart::create([
            'name' => 'DEPART '.uniqid(),
            'date' => now(),
            'trajet_id' => $trajet->id,
            'closed' => false,
            'locked' => false,
            'canceled' => false,
        ]);
        $bus = $depart->buses()->create([
            'name' => 'Bus Test',
            'nombre_place' => 50,
            'ticket_price' => 4000,
            'gp_ticket_price' => 6000,
            'closed' => false,
        ]);

        return [$trajet, $depart, $bus];
    }

    private function createBookingForNewCustomer(Trajet $trajet, Depart $depart, int $busId, ?Ambassador $ambassador = null): Booking
    {
        $customer = Customer::create([
            'prenom' => 'Test',
            'nom' => 'Client',
            'phone_number' => fake()->unique()->numerify('77#######'),
            'ambassador_id' => $ambassador?->id,
        ]);

        return Booking::create([
            'customer_id' => $customer->id,
            'depart_id' => $depart->id,
            'bus_id' => $busId,
            'point_dep_id' => PointDep::create(['name' => 'Gare '.uniqid(), 'trajet_id' => $trajet->id])->id,
            'destination_id' => Destination::create(['name' => 'Centre', 'trajet_id' => $trajet->id])->id,
            'paye' => true,
            'ambassador_id' => $ambassador?->id,
        ]);
    }

    public function test_a_user_without_full_access_cannot_open_the_dashboard(): void
    {
        $this->actingAs($this->createUserWithPermissions([]))
            ->get(route('back-office.dashboard'))
            ->assertForbidden();
    }

    public function test_the_dashboard_defaults_to_the_current_month(): void
    {
        $response = $this->actingAs($this->createUserWithFullAccess())->get(route('back-office.dashboard'));

        $response->assertOk();
        /** @var DashboardDTO $dashboard */
        $dashboard = $response->viewData('dashboard');
        $this->assertSame(now()->startOfMonth()->toDateString(), $dashboard->periodStart->toDateString());
        $this->assertSame(now()->endOfMonth()->toDateString(), $dashboard->periodEnd->toDateString());
    }

    public function test_the_dashboard_gathers_the_figures_of_the_selected_trajet(): void
    {
        [$trajet, $depart, $bus] = $this->createTrajetWithDepartAndBus();
        $ambassador = Ambassador::factory()->create();
        $bookingBroughtByAmbassador = $this->createBookingForNewCustomer($trajet, $depart, $bus->id, $ambassador);
        $this->createBookingForNewCustomer($trajet, $depart, $bus->id);

        AmbassadorEarning::factory()->create(['ambassador_id' => $ambassador->id, 'booking_id' => $bookingBroughtByAmbassador->id, 'amount' => 500]);
        AmbassadorEarning::factory()->approved()->create(['ambassador_id' => $ambassador->id, 'booking_id' => $bookingBroughtByAmbassador->id, 'amount' => 700]);
        AmbassadorEarning::factory()->cancelled()->create(['ambassador_id' => $ambassador->id, 'booking_id' => $bookingBroughtByAmbassador->id, 'amount' => 900]);

        [$otherTrajet, $otherDepart, $otherBus] = $this->createTrajetWithDepartAndBus();
        $otherBooking = $this->createBookingForNewCustomer($otherTrajet, $otherDepart, $otherBus->id, $ambassador);
        AmbassadorEarning::factory()->create(['ambassador_id' => $ambassador->id, 'booking_id' => $otherBooking->id, 'amount' => 300]);

        $response = $this->actingAs($this->createUserWithFullAccess())
            ->get(route('back-office.dashboard', ['trajet_id' => $trajet->id]));

        $response->assertOk();
        /** @var DashboardDTO $dashboard */
        $dashboard = $response->viewData('dashboard');
        $this->assertSame(2, $dashboard->newCustomersCount);
        $this->assertSame(1, $dashboard->newCustomersBroughtByAmbassadorsCount);
        $this->assertSame(50.0, $dashboard->newCustomersBroughtByAmbassadorsPercentage());
        $this->assertSame(2, $dashboard->bookingsCount);
        $this->assertSame(1, $dashboard->bookingsBroughtByAmbassadorsCount);
        $this->assertSame(50.0, $dashboard->bookingsBroughtByAmbassadorsPercentage());
        $this->assertSame(1, $dashboard->departsCount);
        $this->assertSame(1, $dashboard->busesCount);
        $this->assertSame(500, $dashboard->amountDueToAmbassadors->pendingBalance);
        $this->assertSame(700, $dashboard->amountDueToAmbassadors->availableBalance);
        $this->assertSame(1200, $dashboard->amountDueToAmbassadors->totalBalance);
    }

    public function test_records_outside_the_selected_period_are_left_out(): void
    {
        [$trajet, $depart, $bus] = $this->createTrajetWithDepartAndBus();
        $oldBooking = $this->createBookingForNewCustomer($trajet, $depart, $bus->id);
        $oldBooking->customer->forceFill(['created_at' => now()->subMonths(3)])->save();
        $oldBooking->forceFill(['created_at' => now()->subMonths(3)])->save();
        $this->createBookingForNewCustomer($trajet, $depart, $bus->id);

        $response = $this->actingAs($this->createUserWithFullAccess())->get(route('back-office.dashboard', [
            'trajet_id' => $trajet->id,
            'date_from' => now()->startOfMonth()->toDateString(),
            'date_to' => now()->endOfMonth()->toDateString(),
        ]));

        /** @var DashboardDTO $dashboard */
        $dashboard = $response->viewData('dashboard');
        $this->assertSame(1, $dashboard->newCustomersCount);
        $this->assertSame(1, $dashboard->bookingsCount);
    }

    public function test_an_end_date_before_the_start_date_is_rejected(): void
    {
        $this->actingAs($this->createUserWithFullAccess())
            ->get(route('back-office.dashboard', ['date_from' => '2026-11-20', 'date_to' => '2026-11-10']))
            ->assertSessionHasErrors('date_to');
    }
}
