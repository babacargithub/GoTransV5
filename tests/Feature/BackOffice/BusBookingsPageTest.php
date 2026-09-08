<?php

namespace Tests\Feature\BackOffice;

use App\Livewire\BackOffice\BusBookings;
use App\Models\Booking;
use App\Models\Bus;
use App\Models\Customer;
use App\Models\Depart;
use App\Models\HeureDepart;
use App\Models\PointDep;
use App\Models\Seat;
use App\Models\Ticket;
use App\Models\Trajet;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class BusBookingsPageTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return array{bus: Bus, booking: Booking, customer: Customer}
     */
    private function createBusWithOnePassenger(): array
    {
        $trajet = Trajet::query()
            ->has('pointDeps')
            ->has('destinations')
            ->firstOrFail();

        $pointDep = $trajet->pointDeps()->firstOrFail();
        $destination = $trajet->destinations()->firstOrFail();

        $depart = Depart::create([
            'name' => 'DEPART TEST '.uniqid(),
            'date' => now()->addDays(3),
            'trajet_id' => $trajet->id,
            'closed' => false,
            'locked' => false,
            'canceled' => false,
        ]);

        $bus = $depart->buses()->create([
            'name' => 'Bus Test Alpha',
            'nombre_place' => 57,
            'ticket_price' => 3550,
            'gp_ticket_price' => 6000,
            'closed' => false,
        ]);

        HeureDepart::create([
            'depart_id' => $depart->id,
            'bus_id' => $bus->id,
            'point_dep_id' => $pointDep->id,
            'heureDepart' => '07:00:00',
            'arretBus' => 'Station principale',
        ]);

        $customer = Customer::create([
            'prenom' => 'Awa',
            'nom' => 'Ndiaye',
            'phone_number' => 770000000 + random_int(1, 9999999),
        ]);

        $booking = $bus->bookings()->create([
            'customer_id' => $customer->id,
            'depart_id' => $depart->id,
            'point_dep_id' => $pointDep->id,
            'destination_id' => $destination->id,
            'paye' => false,
        ]);

        return [
            'bus' => $bus->fresh(),
            'booking' => $booking->fresh(),
            'customer' => $customer,
        ];
    }

    /**
     * Builds an extra upcoming depart with a single bus that has real, free seats,
     * so a booking can actually be transferred onto it.
     */
    private function createUpcomingDepartWithBus(int $numberOfSeats = 5, ?string $busName = null): Bus
    {
        $trajet = Trajet::query()
            ->has('pointDeps')
            ->has('destinations')
            ->firstOrFail();

        $depart = Depart::create([
            'name' => 'DEPART CIBLE '.uniqid(),
            'date' => now()->addDays(4),
            'trajet_id' => $trajet->id,
            'closed' => false,
            'locked' => false,
            'canceled' => false,
        ]);

        $bus = $depart->buses()->create([
            'name' => $busName ?? 'Bus Cible '.uniqid(),
            'nombre_place' => $numberOfSeats,
            'ticket_price' => 3550,
            'gp_ticket_price' => 6000,
            'closed' => false,
        ]);

        $busSeats = Seat::query()
            ->orderBy('number')
            ->limit($numberOfSeats)
            ->get()
            ->map(fn (Seat $seat): array => [
                'seat_id' => $seat->id,
                'booked' => false,
                'price' => 3550,
            ])
            ->all();

        $bus->seats()->createMany($busSeats);

        return $bus->fresh();
    }

    /**
     * How a customer's name is shown on the page: capitalised first name(s), UPPERCASE last name.
     */
    private function displayedName(Customer $customer): string
    {
        return Str::title($customer->prenom).' '.Str::upper($customer->nom);
    }

    private function attachWaveTicket(Booking $booking, string $transactionId = 'cos-2600kqw0r1h1c', string $paymentMethod = 'wave'): Ticket
    {
        $ticket = new Ticket;
        $ticket->forceFill([
            'number' => random_int(1_000_000, 9_999_999_999),
            'price' => 3550,
            'payment_method' => $paymentMethod,
            'comment' => $transactionId,
            'used' => true,
            'soldBy' => 'system',
            'soldAt' => now(),
            'expiryDate' => now()->addDays(30),
        ])->save();

        $booking->ticket()->associate($ticket)->save();

        return $ticket;
    }

    public function test_it_lists_the_passengers_of_a_bus(): void
    {
        ['bus' => $bus, 'customer' => $customer] = $this->createBusWithOnePassenger();

        $response = $this->actingAs(User::factory()->create())
            ->get(route('back-office.buses.bookings', $bus));

        $response->assertOk();
        $response->assertSeeLivewire(BusBookings::class);
        $response->assertSee($bus->name);
        $response->assertSee($bus->depart->identifier(with_trajet_prefix: true));
        $response->assertSee($this->displayedName($customer));
        $response->assertSee((string) $customer->phone_number);
        // The phone number is a tel: link so a tap opens the dialer.
        $response->assertSeeHtml('href="tel:'.$customer->phone_number.'"');
        $response->assertSee('Total réservations');
        $response->assertSee('Sièges attribués');
        $response->assertSee('Billets payés');

        // Per-row action buttons (icons only), carried over from the legacy Vue admin.
        $response->assertSee('Modifier la réservation');
        $response->assertSee('Annuler la réservation');
        $response->assertSee('Transférer vers un autre bus');
        $response->assertSee('Détails de la réservation');
    }

    public function test_it_normalises_passenger_names_with_capitalised_first_names_and_uppercase_last_name(): void
    {
        ['bus' => $bus] = $this->createBusWithOnePassenger();

        $trajet = $bus->depart->trajet;

        foreach (['serigne fallou' => 'seye', 'babacar' => 'seye'] as $prenom => $nom) {
            $customer = Customer::create([
                'prenom' => $prenom,
                'nom' => $nom,
                'phone_number' => 770000000 + random_int(1, 9999999),
            ]);

            $bus->bookings()->create([
                'customer_id' => $customer->id,
                'depart_id' => $bus->depart_id,
                'point_dep_id' => $trajet->pointDeps()->firstOrFail()->id,
                'destination_id' => $trajet->destinations()->firstOrFail()->id,
                'paye' => false,
            ]);
        }

        Livewire::test(BusBookings::class, ['bus' => $bus])
            ->assertSee('Serigne Fallou SEYE')
            ->assertSee('Babacar SEYE')
            ->assertDontSee('serigne fallou seye');
    }

    public function test_it_orders_unpaid_bookings_newest_first_then_paid_bookings_by_seat_number(): void
    {
        ['bus' => $bus, 'customer' => $oldestUnpaidCustomer] = $this->createBusWithOnePassenger();

        $makeCustomer = function (string $prenom): Customer {
            return Customer::create([
                'prenom' => $prenom,
                'nom' => 'Test',
                'phone_number' => 770000000 + random_int(1, 9999999),
            ]);
        };

        $makeBooking = function (Customer $customer) use ($bus): Booking {
            return $bus->bookings()->create([
                'customer_id' => $customer->id,
                'depart_id' => $bus->depart_id,
                'point_dep_id' => $bus->depart->trajet->pointDeps()->firstOrFail()->id,
                'destination_id' => $bus->depart->trajet->destinations()->firstOrFail()->id,
                'paye' => false,
            ]);
        };

        $newestUnpaidCustomer = $makeCustomer('Newest');
        $makeBooking($newestUnpaidCustomer);

        $lowSeatCustomer = $makeCustomer('LowSeat');
        $lowSeatBooking = $makeBooking($lowSeatCustomer);
        $highSeatCustomer = $makeCustomer('HighSeat');
        $highSeatBooking = $makeBooking($highSeatCustomer);

        [$lowSeat, $highSeat] = Seat::query()->orderBy('number')->limit(2)->get()->all();

        $lowBusSeat = $bus->seats()->create(['seat_id' => $lowSeat->id, 'booked' => true, 'price' => 3550]);
        $highBusSeat = $bus->seats()->create(['seat_id' => $highSeat->id, 'booked' => true, 'price' => 3550]);

        $this->attachWaveTicket($lowSeatBooking, 'TX-LOW');
        $lowSeatBooking->seat()->associate($lowBusSeat)->save();
        $this->attachWaveTicket($highSeatBooking, 'TX-HIGH');
        $highSeatBooking->seat()->associate($highBusSeat)->save();

        Livewire::test(BusBookings::class, ['bus' => $bus])
            ->assertSeeInOrder([
                $this->displayedName($newestUnpaidCustomer),
                $this->displayedName($oldestUnpaidCustomer),
                $this->displayedName($lowSeatCustomer),
                $this->displayedName($highSeatCustomer),
            ]);
    }

    public function test_an_unpaid_booking_shows_the_pay_and_reminder_buttons(): void
    {
        ['bus' => $bus, 'booking' => $booking] = $this->createBusWithOnePassenger();

        Livewire::test(BusBookings::class, ['bus' => $bus])
            ->assertSee('Payer')
            ->assertSee('Wave')
            ->assertSee('OM')
            ->assertSeeHtml("askToConfirmTicketPayment({$booking->id})")
            ->assertSeeHtml("sendWavePaymentReminder({$booking->id})")
            ->assertSeeHtml("sendOrangeMoneyPaymentReminder({$booking->id})");
    }

    public function test_it_shows_an_empty_state_when_the_bus_has_no_passengers(): void
    {
        $trajet = Trajet::query()->firstOrFail();

        $depart = Depart::create([
            'name' => 'DEPART TEST '.uniqid(),
            'date' => now()->addDays(3),
            'trajet_id' => $trajet->id,
            'closed' => false,
            'locked' => false,
            'canceled' => false,
        ]);

        $bus = $depart->buses()->create([
            'name' => 'Bus Test Vide',
            'nombre_place' => 57,
            'ticket_price' => 3550,
            'gp_ticket_price' => 6000,
            'closed' => false,
        ]);

        Livewire::test(BusBookings::class, ['bus' => $bus])
            ->assertSee('Aucune réservation');
    }

    public function test_cancelling_a_booking_asks_for_confirmation_before_deleting(): void
    {
        ['bus' => $bus, 'booking' => $booking] = $this->createBusWithOnePassenger();

        Livewire::test(BusBookings::class, ['bus' => $bus])
            ->call('askToConfirmBookingCancellation', $booking->id)
            ->assertSet('showConfirmationModal', true)
            ->assertSet('pendingBookingId', $booking->id)
            ->assertSet('pendingActionName', 'cancel-booking')
            ->assertSee('Annuler la réservation');

        // Nothing happens until the modal is confirmed.
        $this->assertNotSoftDeleted($booking);
    }

    public function test_confirming_the_modal_cancels_the_booking_without_a_reload(): void
    {
        ['bus' => $bus, 'booking' => $booking, 'customer' => $customer] = $this->createBusWithOnePassenger();

        Livewire::test(BusBookings::class, ['bus' => $bus])
            ->call('askToConfirmBookingCancellation', $booking->id)
            ->call('confirmPendingAction')
            ->assertSet('showConfirmationModal', false)
            ->assertSet('pendingBookingId', null)
            ->assertSee('Réservation de '.$this->displayedName($customer).' annulée.')
            ->assertSee('Aucune réservation');

        $this->assertSoftDeleted($booking);
    }

    public function test_paying_a_ticket_asks_for_confirmation_before_charging(): void
    {
        ['bus' => $bus, 'booking' => $booking] = $this->createBusWithOnePassenger();

        Livewire::test(BusBookings::class, ['bus' => $bus])
            ->call('askToConfirmTicketPayment', $booking->id)
            ->assertSet('showConfirmationModal', true)
            ->assertSet('pendingActionName', 'collect-ticket-payment')
            ->assertSee('Encaisser le paiement');

        // The ticket is only charged once the modal is confirmed.
        $this->assertNull($booking->fresh()->ticket_id);
    }

    public function test_the_payment_reminder_route_rejects_an_unknown_method_with_a_flash_error(): void
    {
        ['booking' => $booking] = $this->createBusWithOnePassenger();

        $response = $this->actingAs(User::factory()->create())
            ->post(route('back-office.bookings.trigger-payment-request', [$booking, 'paypal']));

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Méthode de paiement non supportée.');
    }

    public function test_the_details_menu_shows_the_booking_reference_information(): void
    {
        ['bus' => $bus, 'booking' => $booking] = $this->createBusWithOnePassenger();

        Livewire::test(BusBookings::class, ['bus' => $bus])
            ->assertSee('Informations réservation')
            ->assertSee((string) $booking->id)
            // No ticket yet: no transaction id, no group, no download button.
            ->assertSee('N/A')
            ->assertSee('Aucun billet émis pour cette réservation.')
            ->assertDontSee('Télécharger le ticket');
    }

    public function test_the_details_menu_offers_a_wave_refund_and_the_ticket_download(): void
    {
        ['bus' => $bus, 'booking' => $booking] = $this->createBusWithOnePassenger();
        $this->attachWaveTicket($booking, 'cos-2600kqw0r1h1c');

        Livewire::test(BusBookings::class, ['bus' => $bus])
            ->assertSee('cos-2600kqw0r1h1c')
            ->assertSee('Rembourser')
            ->assertSee('Télécharger le ticket')
            ->assertSeeHtml('wire:click="askToConfirmRefund('.$booking->id.')"');
    }

    public function test_a_non_wave_ticket_cannot_be_refunded_from_the_details_menu(): void
    {
        ['bus' => $bus, 'booking' => $booking] = $this->createBusWithOnePassenger();
        $this->attachWaveTicket($booking, 'CASH-0001', paymentMethod: 'cash');

        Livewire::test(BusBookings::class, ['bus' => $bus])
            ->assertSee('Télécharger le ticket')
            ->assertDontSee('Rembourser');
    }

    public function test_requesting_a_refund_asks_for_confirmation_before_anything_happens(): void
    {
        ['bus' => $bus, 'booking' => $booking] = $this->createBusWithOnePassenger();
        $this->attachWaveTicket($booking);

        Livewire::test(BusBookings::class, ['bus' => $bus])
            ->call('askToConfirmRefund', $booking->id)
            ->assertSet('showConfirmationModal', true)
            ->assertSet('pendingBookingId', $booking->id)
            ->assertSet('pendingActionName', 'refund-booking')
            ->assertSee('Rembourser la réservation');

        $this->assertNotSoftDeleted($booking);
    }

    public function test_the_single_booking_ticket_page_renders_a_printable_ticket(): void
    {
        ['booking' => $booking, 'customer' => $customer] = $this->createBusWithOnePassenger();
        $ticket = $this->attachWaveTicket($booking);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('back-office.bookings.ticket', $booking));

        $response->assertOk();
        $response->assertSee('BILLET DE VOYAGE');
        $response->assertSee((string) $ticket->number);
        // The printable ticket is a separate legacy view and keeps the raw customer name.
        $response->assertSee($customer->full_name);
    }

    public function test_the_single_booking_ticket_page_404s_when_the_booking_has_no_ticket(): void
    {
        ['booking' => $booking] = $this->createBusWithOnePassenger();

        $this->actingAs(User::factory()->create())
            ->get(route('back-office.bookings.ticket', $booking))
            ->assertNotFound();
    }

    public function test_the_transfer_modal_lists_upcoming_departs_with_their_buses(): void
    {
        ['bus' => $bus, 'booking' => $booking] = $this->createBusWithOnePassenger();
        $targetBus = $this->createUpcomingDepartWithBus(busName: 'Bus Destination Beta');

        Livewire::test(BusBookings::class, ['bus' => $bus])
            ->call('openBookingTransferModal', $booking->id)
            ->assertSet('showTransferModal', true)
            ->assertSet('transferBookingId', $booking->id)
            ->assertSee($targetBus->depart->identifier(with_trajet_prefix: true))
            ->assertSee('Bus Destination Beta')
            ->assertSeeHtml('wire:click="transferBookingToBus('.$targetBus->id.')"')
            // The current bus is never offered as a transfer target.
            ->assertDontSeeHtml('wire:click="transferBookingToBus('.$bus->id.')"');
    }

    public function test_transferring_a_booking_moves_it_to_the_chosen_bus_without_a_reload(): void
    {
        ['bus' => $bus, 'booking' => $booking, 'customer' => $customer] = $this->createBusWithOnePassenger();
        $targetBus = $this->createUpcomingDepartWithBus();

        Livewire::test(BusBookings::class, ['bus' => $bus])
            ->call('openBookingTransferModal', $booking->id)
            ->call('transferBookingToBus', $targetBus->id)
            ->assertSet('showTransferModal', false)
            ->assertSet('transferBookingId', null)
            ->assertSee('Réservation de '.$this->displayedName($customer).' transférée vers '.$targetBus->name.'.')
            ->assertSee('Aucune réservation');

        $booking->refresh();
        $this->assertSame($targetBus->id, $booking->bus_id);
        $this->assertSame($targetBus->depart_id, $booking->depart_id);
    }

    public function test_transferring_reports_the_legacy_error_and_keeps_the_booking_in_place(): void
    {
        ['bus' => $bus, 'booking' => $booking] = $this->createBusWithOnePassenger();
        // A full target bus (no free seats): the legacy controller rejects the transfer.
        $targetBus = $this->createUpcomingDepartWithBus(numberOfSeats: 0);

        Livewire::test(BusBookings::class, ['bus' => $bus])
            ->call('openBookingTransferModal', $booking->id)
            ->call('transferBookingToBus', $targetBus->id)
            ->assertSet('showTransferModal', true)
            ->assertSet('transferErrorMessage', "Il n'y a pas de place disponible pour ce bus !");

        $this->assertSame($bus->id, $booking->fresh()->bus_id);
    }

    public function test_the_edit_modal_prefills_the_booking_and_only_offers_stops_of_its_trajet(): void
    {
        ['bus' => $bus, 'booking' => $booking] = $this->createBusWithOnePassenger();

        $bookingTrajet = $booking->depart->trajet;
        $otherTrajetPointDep = PointDep::query()
            ->where('trajet_id', '!=', $bookingTrajet->id)
            ->firstOrFail();

        Livewire::test(BusBookings::class, ['bus' => $bus])
            ->call('openBookingEditModal', $booking->id)
            ->assertSet('showEditModal', true)
            ->assertSet('editBookingId', $booking->id)
            ->assertSet('editPointDepId', $booking->point_dep_id)
            ->assertSet('editDestinationId', $booking->destination_id)
            ->assertSee($bookingTrajet->pointDeps()->firstOrFail()->name)
            ->assertDontSee($otherTrajetPointDep->name);
    }

    public function test_editing_a_booking_updates_the_pickup_and_destination_without_a_reload(): void
    {
        ['bus' => $bus, 'booking' => $booking, 'customer' => $customer] = $this->createBusWithOnePassenger();

        $trajet = $booking->depart->trajet;
        $newPointDep = $trajet->pointDeps()->where('id', '!=', $booking->point_dep_id)->firstOrFail();
        $newDestination = $trajet->destinations()->where('id', '!=', $booking->destination_id)->firstOrFail();

        Livewire::test(BusBookings::class, ['bus' => $bus])
            ->call('openBookingEditModal', $booking->id)
            ->set('editPointDepId', $newPointDep->id)
            ->set('editDestinationId', $newDestination->id)
            ->call('saveBookingEdit')
            ->assertSet('showEditModal', false)
            ->assertSet('editBookingId', null)
            ->assertSee('Réservation de '.$this->displayedName($customer).' mise à jour.');

        $booking->refresh();
        $this->assertSame($newPointDep->id, $booking->point_dep_id);
        $this->assertSame($newDestination->id, $booking->destination_id);
    }

    public function test_editing_a_booking_rejects_a_stop_that_belongs_to_another_trajet(): void
    {
        ['bus' => $bus, 'booking' => $booking] = $this->createBusWithOnePassenger();

        $foreignPointDep = PointDep::query()
            ->where('trajet_id', '!=', $booking->depart->trajet_id)
            ->firstOrFail();

        Livewire::test(BusBookings::class, ['bus' => $bus])
            ->call('openBookingEditModal', $booking->id)
            ->set('editPointDepId', $foreignPointDep->id)
            ->call('saveBookingEdit')
            ->assertHasErrors(['editPointDepId' => 'exists']);

        $this->assertNotSame($foreignPointDep->id, $booking->fresh()->point_dep_id);
    }

    public function test_the_legacy_api_update_route_changes_the_pickup_and_destination(): void
    {
        ['booking' => $booking] = $this->createBusWithOnePassenger();

        $trajet = $booking->depart->trajet;
        $newPointDep = $trajet->pointDeps()->where('id', '!=', $booking->point_dep_id)->firstOrFail();
        $newDestination = $trajet->destinations()->where('id', '!=', $booking->destination_id)->firstOrFail();

        Sanctum::actingAs(User::factory()->create());

        $this->putJson("/api/bookings/{$booking->id}", [
            'point_dep_id' => $newPointDep->id,
            'destination_id' => $newDestination->id,
        ])->assertOk();

        $booking->refresh();
        $this->assertSame($newPointDep->id, $booking->point_dep_id);
        $this->assertSame($newDestination->id, $booking->destination_id);
    }

    public function test_the_legacy_api_update_route_rejects_a_stop_from_another_trajet(): void
    {
        ['booking' => $booking] = $this->createBusWithOnePassenger();

        $foreignPointDep = PointDep::query()
            ->where('trajet_id', '!=', $booking->depart->trajet_id)
            ->firstOrFail();

        Sanctum::actingAs(User::factory()->create());

        $this->putJson("/api/bookings/{$booking->id}", [
            'point_dep_id' => $foreignPointDep->id,
            'destination_id' => $booking->destination_id,
        ])->assertStatus(422)->assertJsonValidationErrorFor('point_dep_id');

        $this->assertNotSame($foreignPointDep->id, $booking->fresh()->point_dep_id);
    }

    public function test_the_shared_controller_still_returns_json_for_the_legacy_api(): void
    {
        ['bus' => $bus, 'booking' => $booking] = $this->createBusWithOnePassenger();

        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson("/api/buses/{$bus->id}/bookings");

        $response->assertOk();

        $bookingRow = collect($response->json())->firstWhere('id', $booking->id);

        $this->assertNotNull($bookingRow, 'The created booking is missing from the JSON payload.');
        $this->assertArrayHasKey('client', $bookingRow);
        $this->assertArrayHasKey('pointDep', $bookingRow);
        $this->assertArrayHasKey('destination', $bookingRow);
    }
}
