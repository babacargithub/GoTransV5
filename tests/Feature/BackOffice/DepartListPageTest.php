<?php

namespace Tests\Feature\BackOffice;

use App\Models\Depart;
use App\Models\Trajet;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DepartListPageTest extends TestCase
{
    use DatabaseTransactions;

    private function createUpcomingDepartWithBus(): Depart
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

        $depart->buses()->create([
            'name' => 'Bus Test Alpha',
            'nombre_place' => 57,
            'ticket_price' => 3550,
            'gp_ticket_price' => 6000,
            'closed' => false,
        ]);

        return $depart->fresh();
    }

    /**
     * Auth middleware is temporarily disabled on the back-office routes for quick testing.
     * When it is restored, this should assert a redirect to the login page for guests.
     */
    public function test_the_page_is_currently_reachable_without_authentication(): void
    {
        $this->createUpcomingDepartWithBus();

        $this->get(route('back-office.departs.index'))->assertOk();
    }

    public function test_it_lists_upcoming_departs_with_their_buses_and_counts(): void
    {
        $depart = $this->createUpcomingDepartWithBus();

        $response = $this->actingAs(User::factory()->create())
            ->get(route('back-office.departs.index'));

        $response->assertOk();
        $response->assertViewIs('back-office.departs.index');
        $response->assertSee($depart->identifier(with_trajet_prefix: true));
        $response->assertSee('Bus Test Alpha');
        $response->assertSee('Réservations');
        $response->assertSee('Billets payés');
        $response->assertSee('Places réservées');
        $response->assertSee('Ajouter un bus');
        $response->assertSee('Ventes de billets');
        $response->assertSee('Modifier le départ');
        $response->assertSee('Exporter les données');

        // Each bus row links to its dedicated bookings page.
        $response->assertSee('Voir les réservations');
        $response->assertSee(route('back-office.buses.bookings', $depart->buses()->firstOrFail()), false);

        // Overflow menu actions carried over from the legacy Vue admin.
        $response->assertSee('Voir le bilan');
        $response->assertSee('Répartition des clients');
        $response->assertSee('Gestion des rendez-vous');
        $response->assertSee('Envoi des rendez-vous');
        $response->assertSee('Annuler ce départ');
    }

    public function test_a_closed_bus_is_flagged_with_an_icon_and_not_the_word_ferme(): void
    {
        $depart = $this->createUpcomingDepartWithBus();
        $depart->buses()->firstOrFail()->update(['closed' => true]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('back-office.departs.index'));

        $response->assertOk();
        $response->assertDontSee('>Fermé<', false);
        $response->assertSee('Fermé'); // present only as the tooltip label
    }

    public function test_the_shared_controller_still_returns_json_for_the_legacy_api(): void
    {
        $depart = $this->createUpcomingDepartWithBus();
        $bus = $depart->buses()->firstOrFail();

        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/departs');

        $response->assertOk();

        $departRow = collect($response->json('data'))->firstWhere('id', $depart->id);

        $this->assertNotNull($departRow, 'The created depart is missing from the JSON payload.');
        $this->assertSame($depart->identifier(with_trajet_prefix: true), $departRow['name']);
        $this->assertSame($bus->id, $departRow['buses'][0]['id']);
        $this->assertArrayHasKey('numberOfBookings', $departRow['buses'][0]);
        $this->assertArrayHasKey('numberOfTicketSold', $departRow['buses'][0]);
        $this->assertArrayHasKey('numberOfBookedSeats', $departRow['buses'][0]);
    }
}
