<?php

namespace Tests\Feature\BackOffice;

use App\Livewire\BackOffice\BusBookings;
use App\Models\Booking;
use App\Models\Bus;
use App\Models\Customer;
use App\Models\Depart;
use App\Models\HeureDepart;
use App\Models\Seat;
use App\Models\Ticket;
use App\Models\Trajet;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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
        $response->assertSee($customer->full_name);
        $response->assertSee((string) $customer->phone_number);
        $response->assertSee('Total réservations');
        $response->assertSee('Sièges attribués');
        $response->assertSee('Billets payés');

        // Per-row action buttons (icons only), carried over from the legacy Vue admin.
        $response->assertSee('Modifier la réservation');
        $response->assertSee('Annuler la réservation');
        $response->assertSee('Transférer vers un autre bus');
        $response->assertSee('Détails de la réservation');
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
            ->assertSee('Réservation de '.$customer->full_name.' annulée.')
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
            ->assertSee('Réservation de '.$customer->full_name.' transférée vers '.$targetBus->name.'.')
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
