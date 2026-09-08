<?php

namespace Tests\Feature\BackOffice;

use App\Livewire\BackOffice\DepartList;
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
     * @return array{depart: Depart, bus: Bus, customer: Customer, ticket: Ticket}
     */
    private function createUpcomingDepartWithOnePaidSeatedPassenger(): array
    {
        $trajet = Trajet::query()
            ->has('pointDeps')
            ->has('destinations')
            ->firstOrFail();

        $pointDep = $trajet->pointDeps()->firstOrFail();
        $destination = $trajet->destinations()->firstOrFail();

        $depart = Depart::create([
            'name' => 'DEPART VENTES '.uniqid(),
            'date' => now()->addDays(3),
            'trajet_id' => $trajet->id,
            'closed' => false,
            'locked' => false,
            'canceled' => false,
        ]);

        $bus = $depart->buses()->create([
            'name' => 'Bus Ventes',
            'nombre_place' => 5,
            'ticket_price' => 3550,
            'gp_ticket_price' => 6000,
            'closed' => false,
        ]);

        $seat = Seat::query()->orderBy('number')->firstOrFail();
        $busSeat = $bus->seats()->create([
            'seat_id' => $seat->id,
            'booked' => true,
            'price' => 3550,
        ]);

        HeureDepart::create([
            'depart_id' => $depart->id,
            'bus_id' => $bus->id,
            'point_dep_id' => $pointDep->id,
            'heureDepart' => '07:00:00',
            'arretBus' => 'Station principale',
        ]);

        $customer = Customer::create([
            'prenom' => 'awa fatou',
            'nom' => 'ndiaye',
            'phone_number' => 770000000 + random_int(1, 9999999),
        ]);

        $ticket = new Ticket;
        $ticket->forceFill([
            'number' => random_int(1_000_000, 9_999_999_999),
            'price' => 3550,
            'payment_method' => 'wave',
            'used' => true,
            'soldBy' => 'agence keur massar',
            'soldAt' => now(),
            'expiryDate' => now()->addDays(30),
        ])->save();

        $bus->bookings()->create([
            'customer_id' => $customer->id,
            'depart_id' => $depart->id,
            'point_dep_id' => $pointDep->id,
            'destination_id' => $destination->id,
            'seat_id' => $busSeat->id,
            'ticket_id' => $ticket->id,
            'paye' => true,
        ]);

        return [
            'depart' => $depart->fresh(),
            'bus' => $bus->fresh(),
            'customer' => $customer,
            'ticket' => $ticket,
        ];
    }

    /**
     * @return array{depart: Depart, bus: Bus, otherBus: Bus, schedules: array<int, HeureDepart>}
     */
    private function createUpcomingDepartWithBusStopSchedules(): array
    {
        $trajet = Trajet::query()
            ->has('pointDeps', '>=', 2)
            ->firstOrFail();

        [$firstPointDep, $secondPointDep] = $trajet->pointDeps()->take(2)->get()->all();

        $depart = Depart::create([
            'name' => 'DEPART RDV '.uniqid(),
            'date' => now()->addDays(3),
            'trajet_id' => $trajet->id,
            'closed' => false,
            'locked' => false,
            'canceled' => false,
        ]);

        $bus = $depart->buses()->create([
            'name' => 'Bus RDV Un',
            'nombre_place' => 57,
            'ticket_price' => 3550,
            'gp_ticket_price' => 6000,
            'closed' => false,
        ]);

        $otherBus = $depart->buses()->create([
            'name' => 'Bus RDV Deux',
            'nombre_place' => 57,
            'ticket_price' => 3550,
            'gp_ticket_price' => 6000,
            'closed' => false,
        ]);

        $schedules = [
            HeureDepart::create([
                'depart_id' => $depart->id,
                'bus_id' => $bus->id,
                'point_dep_id' => $firstPointDep->id,
                'heureDepart' => '07:00:00',
                'arretBus' => 'Devant la pharmacie',
            ]),
            HeureDepart::create([
                'depart_id' => $depart->id,
                'bus_id' => $bus->id,
                'point_dep_id' => $secondPointDep->id,
                'heureDepart' => '07:45:00',
                'arretBus' => 'Rond-point',
            ]),
        ];

        HeureDepart::create([
            'depart_id' => $depart->id,
            'bus_id' => $otherBus->id,
            'point_dep_id' => $firstPointDep->id,
            'heureDepart' => '09:00:00',
            'arretBus' => 'Station essence',
        ]);

        return [
            'depart' => $depart->fresh(),
            'bus' => $bus->fresh(),
            'otherBus' => $otherBus->fresh(),
            'schedules' => $schedules,
        ];
    }

    public function test_the_gestion_des_rendez_vous_dialog_loads_the_first_bus_schedules(): void
    {
        ['depart' => $depart, 'bus' => $bus] = $this->createUpcomingDepartWithBusStopSchedules();
        $firstPointDepName = $depart->trajet->pointDeps()->take(1)->firstOrFail()->name;

        Livewire::actingAs(User::factory()->create())
            ->test(DepartList::class)
            ->call('openScheduleManagement', $depart->id)
            ->assertSet('showScheduleManagementModal', true)
            ->assertSet('scheduleManagementScope', 'bus:'.$bus->id)
            ->assertCount('scheduleManagementRows', 2)
            ->assertSet('scheduleManagementRows.0.rendezVousPoint', 'Devant la pharmacie')
            ->assertSet('scheduleManagementRows.0.rendezVousSchedule', '07:00')
            ->assertSet('scheduleManagementRows.0.isActive', true)
            ->assertSet('scheduleManagementRows.1.rendezVousPoint', 'Rond-point')
            ->assertSee($firstPointDepName)
            ->assertSee('Bus RDV Un')
            ->assertSee('Bus RDV Deux');
    }

    public function test_switching_the_scope_loads_the_other_bus_schedules(): void
    {
        ['depart' => $depart, 'otherBus' => $otherBus] = $this->createUpcomingDepartWithBusStopSchedules();

        Livewire::actingAs(User::factory()->create())
            ->test(DepartList::class)
            ->call('openScheduleManagement', $depart->id)
            ->set('scheduleManagementScope', 'bus:'.$otherBus->id)
            ->assertCount('scheduleManagementRows', 1)
            ->assertSet('scheduleManagementRows.0.rendezVousPoint', 'Station essence');
    }

    public function test_saving_updates_the_rendez_vous_point_time_and_active_state(): void
    {
        ['depart' => $depart, 'schedules' => $schedules] = $this->createUpcomingDepartWithBusStopSchedules();

        Livewire::actingAs(User::factory()->create())
            ->test(DepartList::class)
            ->call('openScheduleManagement', $depart->id)
            ->set('scheduleManagementRows.0.rendezVousPoint', 'Nouveau point')
            ->set('scheduleManagementRows.0.rendezVousSchedule', '06:30')
            ->set('scheduleManagementRows.1.isActive', false)
            ->call('saveScheduleManagementRows')
            ->assertHasNoErrors()
            ->assertSee('Les rendez-vous ont été enregistrés.');

        $this->assertDatabaseHas('heure_departs', [
            'id' => $schedules[0]->id,
            'arretBus' => 'Nouveau point',
            'heureDepart' => '06:30:00',
            'disabled' => 0,
        ]);
        $this->assertDatabaseHas('heure_departs', [
            'id' => $schedules[1]->id,
            'disabled' => 1,
        ]);
    }

    public function test_saving_requires_a_rendez_vous_point_for_every_row(): void
    {
        ['depart' => $depart, 'schedules' => $schedules] = $this->createUpcomingDepartWithBusStopSchedules();

        Livewire::actingAs(User::factory()->create())
            ->test(DepartList::class)
            ->call('openScheduleManagement', $depart->id)
            ->set('scheduleManagementRows.0.rendezVousPoint', '')
            ->call('saveScheduleManagementRows')
            ->assertHasErrors('scheduleManagementRows.0.rendezVousPoint');

        $this->assertDatabaseHas('heure_departs', [
            'id' => $schedules[0]->id,
            'arretBus' => 'Devant la pharmacie',
        ]);
    }

    public function test_cancelling_a_depart_without_bookings_deletes_it_after_confirmation(): void
    {
        $depart = $this->createUpcomingDepartWithBus();
        $departLabel = $depart->identifier(with_trajet_prefix: true);

        Livewire::actingAs(User::factory()->create())
            ->test(DepartList::class)
            ->call('askToCancelDepart', $depart->id)
            ->assertSet('showCancelDepartModal', true)
            ->assertSee('Annuler ce départ ?')
            ->call('confirmCancelDepart')
            ->assertRedirect(route('back-office.departs.index'));

        $this->assertDatabaseMissing('departs', ['id' => $depart->id]);

        $this->actingAs(User::factory()->create())
            ->get(route('back-office.departs.index'))
            ->assertSee('Le départ '.$departLabel.' a été annulé.');
    }

    public function test_cancelling_a_depart_with_bookings_soft_cancels_it_and_hides_it_from_the_list(): void
    {
        ['depart' => $depart, 'bus' => $bus] = $this->createUpcomingDepartWithOnePaidSeatedPassenger();
        $departLabel = $depart->identifier(with_trajet_prefix: true);

        Livewire::actingAs(User::factory()->create())
            ->test(DepartList::class)
            ->assertSee($departLabel)
            ->call('askToCancelDepart', $depart->id)
            ->call('confirmCancelDepart')
            ->assertRedirect(route('back-office.departs.index'));

        $this->assertDatabaseHas('departs', [
            'id' => $depart->id,
            'canceled' => 1,
            'closed' => 1,
            'locked' => 1,
        ]);
        $this->assertDatabaseHas('buses', ['id' => $bus->id]);

        // The global "notCanceled" scope keeps the cancelled départ off the list.
        $this->actingAs(User::factory()->create())
            ->get(route('back-office.departs.index'))
            ->assertSee('a été annulé')
            ->assertDontSee($bus->name);
    }

    public function test_the_cancel_depart_action_is_wired_on_the_menu(): void
    {
        $depart = $this->createUpcomingDepartWithBus();

        $this->actingAs(User::factory()->create())
            ->get(route('back-office.departs.index'))
            ->assertSee('askToCancelDepart('.$depart->id.')', false);
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

    public function test_it_lists_upcoming_departs_with_their_buses_and_actions(): void
    {
        $depart = $this->createUpcomingDepartWithBus();

        $response = $this->actingAs(User::factory()->create())
            ->get(route('back-office.departs.index'));

        $response->assertOk();
        $response->assertSeeLivewire(DepartList::class);
        $response->assertSee($depart->identifier(with_trajet_prefix: true));
        $response->assertSee('Bus Test Alpha');
        $response->assertSee('Ajouter un bus');
        $response->assertSee('Ventes de billets');
        $response->assertSee('Répartition des clients');
        // The two dialogs are wired to their opener actions.
        $response->assertSee('openTicketSales('.$depart->id.')', false);
        $response->assertSee('openBookingsRepartition('.$depart->id.')', false);

        // Each depart and each bus links to its dedicated pages.
        $response->assertSee(route('back-office.departs.add-bus', $depart->id), false);
        $response->assertSee(route('back-office.departs.bookings-export', $depart->id), false);
        $response->assertSee(route('back-office.buses.bookings', $depart->buses()->firstOrFail()), false);
        $response->assertSee(route('back-office.buses.bookings-export', $depart->buses()->firstOrFail()), false);
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

    public function test_the_ventes_de_billets_dialog_shows_ticket_sales_grouped_by_seller(): void
    {
        ['depart' => $depart, 'ticket' => $ticket] = $this->createUpcomingDepartWithOnePaidSeatedPassenger();

        Livewire::actingAs(User::factory()->create())
            ->test(DepartList::class)
            ->call('openTicketSales', $depart->id)
            ->assertSet('showTicketSalesModal', true)
            ->assertSee('agence keur massar')
            ->assertSee(number_format($ticket->price, 0, ',', ' ').' FCFA')
            ->call('closeTicketSales')
            ->assertSet('showTicketSalesModal', false);
    }

    public function test_the_repartition_des_clients_dialog_counts_paid_bookings_per_point_de_depart(): void
    {
        ['depart' => $depart] = $this->createUpcomingDepartWithOnePaidSeatedPassenger();
        $pointDepName = $depart->trajet->pointDeps()->firstOrFail()->name;

        Livewire::actingAs(User::factory()->create())
            ->test(DepartList::class)
            ->call('openBookingsRepartition', $depart->id)
            ->assertSet('showBookingsRepartitionModal', true)
            ->assertSee($pointDepName)
            ->assertSeeText('Total');
    }

    public function test_the_depart_export_renders_a_printable_bookings_document(): void
    {
        ['depart' => $depart, 'customer' => $customer] = $this->createUpcomingDepartWithOnePaidSeatedPassenger();

        $response = $this->actingAs(User::factory()->create())
            ->get(route('back-office.departs.bookings-export', $depart->id));

        $response->assertOk();
        $response->assertSee('Réservations — '.$depart->identifier(with_trajet_prefix: true));
        $response->assertSee('Départ : '.$depart->identifier(with_trajet_prefix: true));
        $response->assertSee('Siège');
        $response->assertSee('Payé par');
        // Name is normalised: capitalised first names, UPPERCASE last name.
        $response->assertSee('Awa Fatou NDIAYE');
        $response->assertSee((string) $customer->phone_number);
        $response->assertSee('wave');
    }

    public function test_the_bus_export_header_mentions_both_the_depart_and_the_bus(): void
    {
        ['bus' => $bus] = $this->createUpcomingDepartWithOnePaidSeatedPassenger();

        $response = $this->actingAs(User::factory()->create())
            ->get(route('back-office.buses.bookings-export', $bus->id));

        $response->assertOk();
        $response->assertSee('Réservations — '.$bus->name);
        $response->assertSee('Bus : '.$bus->name);
        $response->assertSee('Départ : '.$bus->depart->identifier(with_trajet_prefix: true));
        $response->assertSee('Awa Fatou NDIAYE');
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
    }

    public function test_the_header_navigation_shows_the_finances_departs_and_admin_menus(): void
    {
        $this->createUpcomingDepartWithBus();

        $response = $this->actingAs(User::factory()->create())
            ->get(route('back-office.departs.index'));

        $response->assertOk();
        $response->assertSee('data-flux-navbar', false);

        foreach (['Finances', 'Solde des caisses', 'Paiements OM', 'Paiements Wave'] as $financesMenuLabel) {
            $response->assertSee($financesMenuLabel);
        }

        foreach (['Départs', 'Liste des départs', 'Nouveau départ', 'Points de départ', 'Itinéraires', 'Horaires'] as $departsMenuLabel) {
            $response->assertSee($departsMenuLabel);
        }

        foreach (['Admin', 'Employés', 'Trajets', 'Véhicule', 'Paramètres'] as $adminMenuLabel) {
            $response->assertSee($adminMenuLabel);
        }
    }

    public function test_the_layout_keeps_a_visible_sidebar_for_the_upcoming_departs_stats(): void
    {
        $this->createUpcomingDepartWithBus();

        $response = $this->actingAs(User::factory()->create())
            ->get(route('back-office.departs.index'));

        $response->assertOk();
        $response->assertSee('data-flux-sidebar', false);
        $response->assertSee('Statistiques des départs');
    }

    public function test_the_legacy_json_export_endpoint_is_unchanged(): void
    {
        ['depart' => $depart] = $this->createUpcomingDepartWithOnePaidSeatedPassenger();

        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/departs/{$depart->id}/bookings_for_export")
            ->assertOk()
            ->assertJsonStructure([['id', 'seatNumber', 'client', 'phoneNumber', 'pointDep', 'ticketSoldBy']]);
    }
}
