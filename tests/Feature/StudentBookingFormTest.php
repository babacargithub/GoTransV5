<?php

namespace Tests\Feature;

use App\Livewire\Website\StudentBooking;
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

class StudentBookingFormTest extends TestCase
{
    use DatabaseTransactions;

    private function websiteUrl(string $path = '/'): string
    {
        return 'http://'.config('app.public_website_domain').$path;
    }

    /**
     * @return array{trajet: Trajet, depart: Depart, pointDep: PointDep}
     */
    private function createBookableDepart(): array
    {
        $trajet = Trajet::create([
            'name' => 'Caravane '.uniqid(),
            'departure_city' => 'DAKAR',
            'arrival_city' => 'SAINT-LOUIS',
        ]);

        $depart = Depart::create([
            'name' => 'DEPART WEB '.uniqid(),
            'date' => now()->addDays(5),
            'trajet_id' => $trajet->id,
            'visibilite' => Depart::VISIBILITE_ALL_CUSTOMERS,
            'closed' => false,
            'locked' => false,
            'canceled' => false,
        ]);

        $bus = $depart->buses()->create([
            'name' => 'Bus Web Test',
            'nombre_place' => 50,
            'ticket_price' => 4000,
            'gp_ticket_price' => 6000,
            'closed' => false,
        ]);

        // seatsLeft() counts unbooked bus_seats rows, so a bookable bus needs real seats.
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

        Destination::create([
            'name' => 'Dakar centre',
            'trajet_id' => $trajet->id,
        ]);

        return ['trajet' => $trajet, 'depart' => $depart, 'pointDep' => $pointDep];
    }

    private function fakeWaveCheckout(): void
    {
        Http::fake([
            'api.wave.com/*' => Http::response([
                'id' => 'cos-test123',
                'wave_launch_url' => 'https://pay.wave.com/c/cos-test123?a=8000',
            ], 200),
        ]);
    }

    public function test_the_booking_page_renders_the_passenger_count_selector(): void
    {
        ['depart' => $depart] = $this->createBookableDepart();

        $this->get($this->websiteUrl('/reserver/'.$depart->id))
            ->assertStatus(200)
            ->assertSee('Nombre de passagers')
            ->assertSee('Wave')
            ->assertSee('Orange Money')
            ->assertSee('<meta name="robots" content="noindex, follow">', false);
    }

    public function test_choosing_a_passenger_count_renders_that_many_rows(): void
    {
        ['depart' => $depart] = $this->createBookableDepart();

        Livewire::test(StudentBooking::class, ['depart' => $depart])
            ->set('passengersCount', 3)
            ->assertCount('passengers', 3)
            ->assertSee('Passager 1')
            ->assertSee('Passager 3');
    }

    public function test_the_passenger_count_is_capped_at_ten(): void
    {
        ['depart' => $depart] = $this->createBookableDepart();

        Livewire::test(StudentBooking::class, ['depart' => $depart])
            ->set('passengersCount', 25)
            ->assertCount('passengers', 10);
    }

    public function test_phone_number_is_validated_as_the_customer_types(): void
    {
        ['depart' => $depart] = $this->createBookableDepart();

        Livewire::test(StudentBooking::class, ['depart' => $depart])
            ->set('passengersCount', 1)
            ->set('passengers.0.phone_number', '123')
            ->assertHasErrors('passengers.0.phone_number')
            ->set('passengers.0.phone_number', '771234567')
            ->assertHasNoErrors('passengers.0.phone_number');
    }

    public function test_full_name_is_validated_as_the_customer_types(): void
    {
        ['depart' => $depart] = $this->createBookableDepart();

        Livewire::test(StudentBooking::class, ['depart' => $depart])
            ->set('passengersCount', 1)
            ->set('passengers.0.full_name', 'Awa')
            ->assertHasErrors('passengers.0.full_name')
            ->set('passengers.0.full_name', 'Awa Diop')
            ->assertHasNoErrors('passengers.0.full_name');
    }

    public function test_a_duplicate_phone_number_in_the_form_is_rejected(): void
    {
        ['depart' => $depart] = $this->createBookableDepart();

        Livewire::test(StudentBooking::class, ['depart' => $depart])
            ->set('passengersCount', 2)
            ->set('passengers.0.phone_number', '771234567')
            ->set('passengers.1.phone_number', '771234567')
            ->assertHasErrors('passengers.1.phone_number');
    }

    public function test_changing_a_field_clears_its_stale_submission_error(): void
    {
        ['depart' => $depart] = $this->createBookableDepart();

        $component = Livewire::test(StudentBooking::class, ['depart' => $depart])
            ->set('passengersCount', 1)
            ->call('reviewBooking')
            ->assertHasErrors('passengers.0.full_name');

        $component->set('passengers.0.full_name', 'Awa Diop')
            ->assertHasNoErrors('passengers.0.full_name');
    }

    public function test_the_summary_reveals_the_non_refundable_warning_from_params(): void
    {
        ['depart' => $depart, 'pointDep' => $pointDep] = $this->createBookableDepart();

        Livewire::test(StudentBooking::class, ['depart' => $depart])
            ->set('passengersCount', 1)
            ->set('passengers.0.full_name', 'Awa Diop')
            ->set('passengers.0.phone_number', '771234567')
            ->set('passengers.0.point_dep_id', $pointDep->id)
            ->set('paymentMethod', 'wave')
            ->call('reviewBooking')
            ->assertSet('showSummary', true)
            ->call('acknowledgeSummary')
            ->assertSet('showNonRefundableWarning', true)
            ->assertSee("n'est pas remboursable");
    }

    public function test_a_successful_wave_booking_creates_the_bookings_with_a_shared_uuid_and_redirects_to_wave(): void
    {
        $this->fakeWaveCheckout();
        ['depart' => $depart, 'pointDep' => $pointDep] = $this->createBookableDepart();

        Livewire::test(StudentBooking::class, ['depart' => $depart])
            ->set('passengersCount', 2)
            ->set('passengers.0.full_name', 'Awa Diop')
            ->set('passengers.0.phone_number', '771234567')
            ->set('passengers.0.point_dep_id', $pointDep->id)
            ->set('passengers.1.full_name', 'Modou Fall')
            ->set('passengers.1.phone_number', '781234567')
            ->set('passengers.1.point_dep_id', $pointDep->id)
            ->set('paymentMethod', 'wave')
            ->call('reviewBooking')
            ->call('acknowledgeSummary')
            ->call('confirmBooking')
            ->assertRedirect('https://pay.wave.com/c/cos-test123?a=8000');

        $bookings = Booking::where('depart_id', $depart->id)->get();
        $this->assertCount(2, $bookings);
        $this->assertNotNull($bookings->first()->uuid);
        $this->assertCount(1, $bookings->pluck('uuid')->unique());
        $this->assertTrue(Customer::where('phone_number', '771234567')->exists());
    }

    public function test_orange_money_requires_the_paying_number(): void
    {
        ['depart' => $depart, 'pointDep' => $pointDep] = $this->createBookableDepart();

        Livewire::test(StudentBooking::class, ['depart' => $depart])
            ->set('passengersCount', 1)
            ->set('passengers.0.full_name', 'Awa Diop')
            ->set('passengers.0.phone_number', '771234567')
            ->set('passengers.0.point_dep_id', $pointDep->id)
            ->set('paymentMethod', 'om')
            ->call('reviewBooking')
            ->assertHasErrors('orangeMoneyNumber')
            ->assertSet('showSummary', false);
    }

    public function test_the_caravane_page_links_to_the_booking_route(): void
    {
        ['trajet' => $trajet, 'depart' => $depart] = $this->createBookableDepart();

        $this->get($this->websiteUrl('/caravanes/'.$trajet->slug))
            ->assertStatus(200)
            ->assertSee($this->websiteUrl('/reserver/'.$depart->id), false);
    }
}
