<?php

namespace Tests\Feature\Amicale;

use App\Livewire\Website\StudentBooking;
use App\Models\Ambassador;
use App\Models\Amicale;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Depart;
use App\Models\Destination;
use App\Models\PointDep;
use App\Models\Trajet;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class AmicaleBookingAttributionTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return array{depart: Depart, pointDep: PointDep}
     */
    private function createBookableDepart(): array
    {
        $trajet = Trajet::create(['name' => 'Caravane '.uniqid(), 'departure_city' => 'DAKAR', 'arrival_city' => 'SAINT-LOUIS']);
        $depart = Depart::create([
            'name' => 'DEPART AMICALE '.uniqid(),
            'date' => now()->addDays(5),
            'trajet_id' => $trajet->id,
            'visibilite' => Depart::VISIBILITE_ALL_CUSTOMERS,
            'closed' => false,
            'locked' => false,
            'canceled' => false,
        ]);
        $bus = $depart->buses()->create([
            'name' => 'Bus Amicale Test',
            'nombre_place' => 50,
            'ticket_price' => 4000,
            'gp_ticket_price' => 6000,
            'closed' => false,
        ]);
        $bus->seats()->createMany(
            collect(range(1, 10))->map(fn (int $position) => [
                'booked' => false,
                'price' => 4000,
                'position_in_bus' => $position,
            ])->all()
        );
        $pointDep = PointDep::create([
            'name' => 'Gare routière '.uniqid(),
            'trajet_id' => $trajet->id,
            'disabled' => false,
            'visibilite' => Depart::VISIBILITE_ALL_CUSTOMERS,
        ]);
        Destination::create(['name' => 'Dakar centre', 'trajet_id' => $trajet->id]);

        return ['depart' => $depart, 'pointDep' => $pointDep];
    }

    /**
     * Books one passenger on the départ from the given host and returns the resulting booking.
     */
    private function bookFromHost(string $host, Depart $depart, PointDep $pointDep, ?string $ambassadorCode = null): Booking
    {
        Http::fake([
            'api.wave.com/*' => Http::response([
                'id' => 'cos-test123',
                'wave_launch_url' => 'https://pay.wave.com/c/cos-test123?a=4000',
            ], 200),
        ]);
        request()->headers->set('HOST', $host);

        do {
            $phoneNumber = '77'.random_int(1000000, 9999999);
        } while (Customer::where('phone_number', $phoneNumber)->exists());

        Livewire::withQueryParams($ambassadorCode === null ? [] : ['ref' => $ambassadorCode])
            ->test(StudentBooking::class, ['depart' => $depart])
            ->set('passengersCount', 1)
            ->set('passengers.0.full_name', 'Awa Diop')
            ->set('passengers.0.phone_number', $phoneNumber)
            ->set('passengers.0.point_dep_id', $pointDep->id)
            ->set('paymentMethod', 'wave')
            ->call('reviewBooking')
            ->call('acknowledgeSummary')
            ->call('confirmBooking');

        return Booking::where('customer_id', Customer::where('phone_number', $phoneNumber)->firstOrFail()->id)->firstOrFail();
    }

    public function test_a_booking_made_on_an_amicale_subdomain_belongs_to_that_amicale(): void
    {
        ['depart' => $depart, 'pointDep' => $pointDep] = $this->createBookableDepart();
        $amicale = Amicale::factory()->create(['slug' => 'amicale-attribution']);

        $booking = $this->bookFromHost('amicale-attribution.'.config('app.public_website_domain'), $depart, $pointDep);

        $this->assertSame($amicale->id, $booking->amicale_id);
    }

    public function test_a_booking_made_on_the_public_domain_belongs_to_no_amicale(): void
    {
        ['depart' => $depart, 'pointDep' => $pointDep] = $this->createBookableDepart();

        $booking = $this->bookFromHost(config('app.public_website_domain'), $depart, $pointDep);

        $this->assertNull($booking->amicale_id);
    }

    public function test_a_booking_on_an_amicale_site_ignores_an_ambassador_code(): void
    {
        ['depart' => $depart, 'pointDep' => $pointDep] = $this->createBookableDepart();
        $amicale = Amicale::factory()->create(['slug' => 'amicale-sans-ambassadeur']);
        $ambassador = Ambassador::factory()->create();

        $booking = $this->bookFromHost('amicale-sans-ambassadeur.'.config('app.public_website_domain'), $depart, $pointDep, strtolower($ambassador->code));

        $this->assertSame($amicale->id, $booking->amicale_id);
        $this->assertNull($booking->ambassador_id);
    }

    public function test_a_deactivated_amicale_does_not_receive_bookings(): void
    {
        ['depart' => $depart, 'pointDep' => $pointDep] = $this->createBookableDepart();
        Amicale::factory()->inactive()->create(['slug' => 'amicale-inactive']);

        $booking = $this->bookFromHost('amicale-inactive.'.config('app.public_website_domain'), $depart, $pointDep);

        $this->assertNull($booking->amicale_id);
    }
}
