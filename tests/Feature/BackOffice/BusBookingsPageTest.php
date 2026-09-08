<?php

namespace Tests\Feature\BackOffice;

use App\Livewire\BackOffice\BusBookings;
use App\Models\Booking;
use App\Models\Bus;
use App\Models\Customer;
use App\Models\Depart;
use App\Models\HeureDepart;
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
