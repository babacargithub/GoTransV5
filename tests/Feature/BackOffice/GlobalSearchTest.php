<?php

namespace Tests\Feature\BackOffice;

use App\Livewire\BackOffice\GlobalSearch;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Depart;
use App\Models\Trajet;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class GlobalSearchTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return array{customer: Customer, currentBooking: Booking, pastBooking: Booking}
     */
    private function createCustomerWithBookings(): array
    {
        $trajet = Trajet::query()->has('pointDeps')->has('destinations')->firstOrFail();
        $pointDep = $trajet->pointDeps()->firstOrFail();
        $destination = $trajet->destinations()->firstOrFail();

        $customer = Customer::create([
            'prenom' => 'Fatou',
            'nom' => 'Sarr',
            'phone_number' => 770000000 + random_int(1, 8999999),
        ]);

        $upcomingDepart = Depart::create([
            'name' => 'DEPART A VENIR '.uniqid(),
            'date' => now()->addDays(3),
            'trajet_id' => $trajet->id,
            'closed' => false, 'locked' => false, 'canceled' => false,
        ]);
        $upcomingBus = $upcomingDepart->buses()->create([
            'name' => 'Bus Futur', 'nombre_place' => 57, 'ticket_price' => 3550, 'gp_ticket_price' => 6000, 'closed' => false,
        ]);
        $currentBooking = $upcomingBus->bookings()->create([
            'customer_id' => $customer->id,
            'depart_id' => $upcomingDepart->id,
            'point_dep_id' => $pointDep->id,
            'destination_id' => $destination->id,
            'paye' => false,
        ]);

        $pastDepart = Depart::create([
            'name' => 'DEPART PASSE '.uniqid(),
            'date' => now()->subDays(5),
            'trajet_id' => $trajet->id,
            'closed' => false, 'locked' => false, 'canceled' => false,
        ]);
        $pastBus = $pastDepart->buses()->create([
            'name' => 'Bus Passé', 'nombre_place' => 57, 'ticket_price' => 3550, 'gp_ticket_price' => 6000, 'closed' => false,
        ]);
        $pastBooking = $pastBus->bookings()->create([
            'customer_id' => $customer->id,
            'depart_id' => $pastDepart->id,
            'point_dep_id' => $pointDep->id,
            'destination_id' => $destination->id,
            'paye' => false,
        ]);

        return ['customer' => $customer, 'currentBooking' => $currentBooking, 'pastBooking' => $pastBooking];
    }

    public function test_the_search_starts_collapsed_and_toggles_open(): void
    {
        Livewire::actingAs($this->createUserWithFullAccess())
            ->test(GlobalSearch::class)
            ->assertSet('showSearchInput', false)
            ->call('toggleSearchInput')
            ->assertSet('showSearchInput', true)
            ->call('toggleSearchInput')
            ->assertSet('showSearchInput', false);
    }

    public function test_typing_a_valid_phone_number_opens_the_result_dialog(): void
    {
        ['customer' => $customer] = $this->createCustomerWithBookings();

        Livewire::actingAs($this->createUserWithFullAccess())
            ->test(GlobalSearch::class)
            ->set('searchQuery', (string) $customer->phone_number)
            ->assertSet('showResultDialog', true)
            ->assertSet('foundCustomerId', $customer->id)
            ->assertSee('SARR')
            ->assertSee('2 réservation(s)');
    }

    public function test_an_unknown_number_shows_the_no_result_toast_and_no_dialog(): void
    {
        Livewire::actingAs($this->createUserWithFullAccess())
            ->test(GlobalSearch::class)
            ->set('searchQuery', '779999998')
            ->assertSet('showResultDialog', false)
            ->assertDispatched('global-search-no-result');
    }

    public function test_an_invalid_phone_number_does_not_search(): void
    {
        Livewire::actingAs($this->createUserWithFullAccess())
            ->test(GlobalSearch::class)
            ->call('toggleSearchInput')
            ->set('searchQuery', '12345')
            ->call('search')
            ->assertSet('showResultDialog', false)
            ->assertSet('searchValidationMessage', "Le numéro téléphone 12345 n'est pas valide.");
    }

    public function test_the_reservations_tab_shows_only_current_bookings(): void
    {
        ['customer' => $customer, 'currentBooking' => $currentBooking, 'pastBooking' => $pastBooking] = $this->createCustomerWithBookings();

        $component = Livewire::actingAs($this->createUserWithFullAccess())
            ->test(GlobalSearch::class)
            ->set('searchQuery', (string) $customer->phone_number);

        $this->assertSame([$currentBooking->id], collect($component->instance()->currentBookingRows)->pluck('id')->all());
        $this->assertSame([$pastBooking->id], collect($component->instance()->pastBookingRows)->pluck('id')->all());
    }

    public function test_cancelled_bookings_appear_in_the_past_tab_flagged_annule(): void
    {
        ['customer' => $customer, 'currentBooking' => $currentBooking, 'pastBooking' => $pastBooking] = $this->createCustomerWithBookings();
        $currentBooking->update(['deleted_by' => 'Awa Guaye']);
        $currentBooking->delete();

        $component = Livewire::actingAs($this->createUserWithFullAccess())
            ->test(GlobalSearch::class)
            ->set('searchQuery', (string) $customer->phone_number)
            ->assertSee('2 réservation(s)')
            ->assertSet('activeResultTab', 'reservations');

        // The cancelled booking left the "Réservations" tab...
        $this->assertSame([], collect($component->instance()->currentBookingRows)->pluck('id')->all());

        // ...and shows up in "Voyages passés" as "Annulé", with the canceller.
        $this->assertEqualsCanonicalizing(
            [$currentBooking->id, $pastBooking->id],
            collect($component->instance()->pastBookingRows)->pluck('id')->all(),
        );

        $component->set('activeResultTab', 'past')
            ->assertSee('Annulé')
            ->assertSee('par Awa Guaye');
    }

    public function test_cancelling_a_booking_from_the_result_dialog_uses_the_shared_action(): void
    {
        ['customer' => $customer, 'currentBooking' => $currentBooking] = $this->createCustomerWithBookings();

        Livewire::actingAs($this->createUserWithFullAccess())
            ->test(GlobalSearch::class)
            ->set('searchQuery', (string) $customer->phone_number)
            ->call('askToConfirmBookingCancellation', $currentBooking->id)
            ->assertSet('showConfirmationModal', true)
            ->call('confirmPendingAction')
            ->assertSet('showConfirmationModal', false);

        $this->assertSoftDeleted('bookings', ['id' => $currentBooking->id]);
    }

    public function test_a_booking_of_another_customer_cannot_be_actioned(): void
    {
        ['customer' => $customer] = $this->createCustomerWithBookings();
        ['currentBooking' => $otherBooking] = $this->createCustomerWithBookings();

        Livewire::actingAs($this->createUserWithFullAccess())
            ->test(GlobalSearch::class)
            ->set('searchQuery', (string) $customer->phone_number)
            ->call('askToConfirmBookingCancellation', $otherBooking->id)
            ->call('confirmPendingAction');

        $this->assertDatabaseHas('bookings', ['id' => $otherBooking->id, 'deleted_at' => null]);
    }

    public function test_the_search_is_rendered_in_the_back_office_header(): void
    {
        $this->actingAs($this->createUserWithFullAccess())
            ->get(route('back-office.departs.index'))
            ->assertOk()
            ->assertSeeLivewire(GlobalSearch::class);
    }
}
