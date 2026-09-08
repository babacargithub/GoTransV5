<?php

namespace Tests\Feature\BackOffice;

use App\Livewire\BackOffice\DepartList;
use App\Models\Bus;
use App\Models\BusSeat;
use App\Models\Customer;
use App\Models\Depart;
use App\Models\HeureDepart;
use App\Models\Horaire;
use App\Models\Seat;
use App\Models\Ticket;
use App\Models\Trajet;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
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

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->createUpcomingDepartWithBus();

        $this->get(route('back-office.departs.index'))->assertRedirect(route('login'));
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

    public function test_the_bus_actions_menu_wires_the_bus_scoped_dialogs(): void
    {
        $depart = $this->createUpcomingDepartWithBus();
        $bus = $depart->buses()->firstOrFail();

        $response = $this->actingAs(User::factory()->create())
            ->get(route('back-office.departs.index'));

        $response->assertOk();
        $response->assertSee('Actions du bus');
        $response->assertSee('Chiffres');
        $response->assertSee('openBusTicketSales('.$bus->id.')', false);
        $response->assertSee('openScheduleManagement('.$depart->id.', '.$bus->id.')', false);
        $response->assertSee('openBookingsRepartition('.$depart->id.', '.$bus->id.')', false);
    }

    /**
     * @return array{depart: Depart, bus: Bus, seats: Collection<int, BusSeat>}
     */
    private function createUpcomingDepartWithBusSeats(int $numberOfSeats = 4): array
    {
        $depart = $this->createUpcomingDepartWithBus();
        $bus = $depart->buses()->firstOrFail();

        $seats = Seat::query()->orderBy('number')->take($numberOfSeats)->get()
            ->map(fn (Seat $seat) => $bus->seats()->create([
                'seat_id' => $seat->id,
                'booked' => false,
                'price' => 3550,
            ]));

        return ['depart' => $depart->fresh(), 'bus' => $bus->fresh(), 'seats' => $seats];
    }

    public function test_the_bus_menu_merges_itineraire_and_rendez_vous_into_one_bus_scoped_item(): void
    {
        $depart = $this->createUpcomingDepartWithBus();
        $bus = $depart->buses()->firstOrFail();

        $response = $this->actingAs(User::factory()->create())
            ->get(route('back-office.departs.index'));

        $response->assertOk();
        $response->assertSee('Itinéraire / rendez-vous');
        $response->assertSee('openScheduleManagement('.$depart->id.', '.$bus->id.')', false);
        // The legacy split "Itinéraire" + "Gérer les RV" is gone.
        $response->assertDontSee('Gérer les RV');
    }

    public function test_adding_all_bus_stops_creates_a_rendez_vous_row_per_point_de_depart(): void
    {
        $trajet = Trajet::query()->has('pointDeps', '>=', 2)->firstOrFail();
        $horaire = Horaire::query()->where('periode', Horaire::PERIODE_MATIN)->firstOrFail();

        $depart = Depart::create([
            'name' => 'DEPART ARRETS '.uniqid(),
            'date' => now()->addDays(3),
            'trajet_id' => $trajet->id,
            'horaire_id' => $horaire->id,
            'closed' => false,
            'locked' => false,
            'canceled' => false,
        ]);
        $bus = $depart->buses()->create([
            'name' => 'Bus Arrets',
            'nombre_place' => 57,
            'ticket_price' => 3550,
            'gp_ticket_price' => 6000,
            'closed' => false,
        ]);

        Livewire::actingAs(User::factory()->create())
            ->test(DepartList::class)
            ->call('openScheduleManagement', $depart->id, $bus->id)
            ->assertCount('scheduleManagementRows', 0)
            ->call('addAllBusStopSchedules')
            ->assertSee('Les arrêts ont été ajoutés.')
            ->assertCount('scheduleManagementRows', $trajet->pointDeps()->count());
    }

    public function test_the_gestion_des_sieges_dialog_lists_the_bus_seats_with_their_state(): void
    {
        ['bus' => $bus, 'seats' => $seats] = $this->createUpcomingDepartWithBusSeats();
        $seats[0]->update(['booked' => true]);

        Livewire::actingAs(User::factory()->create())
            ->test(DepartList::class)
            ->call('openBusSeats', $bus->id)
            ->assertSet('showBusSeatsModal', true)
            ->assertSee($bus->full_name)
            ->assertCount('busSeatRows', 4)
            ->assertSee('Réservé : 1')
            ->assertSee('Libre : 3');
    }

    public function test_the_gestion_des_sieges_bulk_action_locks_the_selected_seats(): void
    {
        ['bus' => $bus, 'seats' => $seats] = $this->createUpcomingDepartWithBusSeats();

        Livewire::actingAs(User::factory()->create())
            ->test(DepartList::class)
            ->call('openBusSeats', $bus->id)
            ->call('toggleBusSeatSelection', $seats[0]->id)
            ->call('toggleBusSeatSelection', $seats[1]->id)
            ->assertCount('selectedBusSeatIds', 2)
            ->call('performBusSeatsBulkAction', 'lock')
            ->assertCount('selectedBusSeatIds', 0)
            ->assertSee('siège(s) verrouillé(s)');

        $this->assertEquals(1, $seats[0]->fresh()->locked);
        $this->assertEquals(1, $seats[1]->fresh()->locked);
    }

    public function test_the_gestion_des_sieges_action_is_wired_on_the_bus_menu(): void
    {
        $depart = $this->createUpcomingDepartWithBus();
        $bus = $depart->buses()->firstOrFail();

        $this->actingAs(User::factory()->create())
            ->get(route('back-office.departs.index'))
            ->assertSee('openBusSeats('.$bus->id.')', false)
            ->assertSee('Sièges du bus');
    }

    public function test_deleting_a_bus_without_bookings_removes_it_after_confirmation(): void
    {
        ['depart' => $depart, 'seats' => $seats] = $this->createUpcomingDepartWithBusSeats();
        $bus = $depart->buses()->firstOrFail();

        Livewire::actingAs(User::factory()->create())
            ->test(DepartList::class)
            ->call('askToDeleteBus', $bus->id)
            ->assertSet('showDeleteBusModal', true)
            ->assertSee('Supprimer ce bus ?')
            ->call('confirmDeleteBus')
            ->assertSee('a été supprimé');

        $this->assertDatabaseMissing('buses', ['id' => $bus->id]);
        $this->assertDatabaseMissing('bus_seats', ['id' => $seats[0]->id]);
    }

    public function test_deleting_a_bus_with_bookings_is_refused_with_the_legacy_message(): void
    {
        ['bus' => $bus] = $this->createUpcomingDepartWithOnePaidSeatedPassenger();

        Livewire::actingAs(User::factory()->create())
            ->test(DepartList::class)
            ->call('askToDeleteBus', $bus->id)
            ->call('confirmDeleteBus')
            ->assertSee('transférer');

        $this->assertDatabaseHas('buses', ['id' => $bus->id]);
    }

    public function test_the_delete_bus_action_is_wired_on_the_bus_menu(): void
    {
        $depart = $this->createUpcomingDepartWithBus();
        $bus = $depart->buses()->firstOrFail();

        $this->actingAs(User::factory()->create())
            ->get(route('back-office.departs.index'))
            ->assertSee('askToDeleteBus('.$bus->id.')', false)
            ->assertSee('Supprimer le bus');
    }

    public function test_the_cloturer_reouvrir_switch_toggles_the_bus_closed_state(): void
    {
        $depart = $this->createUpcomingDepartWithBus();
        $bus = $depart->buses()->firstOrFail();
        $this->assertFalse((bool) $bus->closed);

        Livewire::actingAs(User::factory()->create())
            ->test(DepartList::class)
            ->call('toggleBusClosed', $bus->id)
            ->assertSee('ont été clôturées');

        $this->assertTrue((bool) $bus->fresh()->closed);

        Livewire::actingAs(User::factory()->create())
            ->test(DepartList::class)
            ->call('toggleBusClosed', $bus->id)
            ->assertSee('ont été réouvertes');

        $this->assertFalse((bool) $bus->fresh()->closed);
    }

    public function test_the_cloturer_reouvrir_action_is_wired_on_the_bus_menu(): void
    {
        $depart = $this->createUpcomingDepartWithBus();
        $bus = $depart->buses()->firstOrFail();

        $this->actingAs(User::factory()->create())
            ->get(route('back-office.departs.index'))
            ->assertSee('toggleBusClosed('.$bus->id.')', false)
            ->assertSee('Clôturer les réservations');
    }

    public function test_the_chiffres_dialog_shows_bus_ticket_sales_grouped_by_seller(): void
    {
        ['bus' => $bus, 'ticket' => $ticket] = $this->createUpcomingDepartWithOnePaidSeatedPassenger();

        Livewire::actingAs(User::factory()->create())
            ->test(DepartList::class)
            ->call('openBusTicketSales', $bus->id)
            ->assertSet('showBusTicketSalesModal', true)
            ->assertSee($bus->full_name)
            ->assertSee('agence keur massar')
            ->assertSee(number_format($ticket->price, 0, ',', ' ').' FCFA')
            ->call('closeBusTicketSales')
            ->assertSet('showBusTicketSalesModal', false);
    }

    public function test_the_repartition_dialog_can_be_scoped_to_a_single_bus(): void
    {
        ['depart' => $depart, 'bus' => $bus] = $this->createUpcomingDepartWithOnePaidSeatedPassenger();
        $pointDepName = $depart->trajet->pointDeps()->firstOrFail()->name;

        Livewire::actingAs(User::factory()->create())
            ->test(DepartList::class)
            ->call('openBookingsRepartition', $depart->id, $bus->id)
            ->assertSet('bookingsRepartitionBusId', $bus->id)
            ->assertSee($bus->name)
            ->assertSee($pointDepName);
    }

    public function test_the_schedule_management_dialog_opens_on_the_given_bus_scope(): void
    {
        ['depart' => $depart, 'otherBus' => $otherBus] = $this->createUpcomingDepartWithBusStopSchedules();

        Livewire::actingAs(User::factory()->create())
            ->test(DepartList::class)
            ->call('openScheduleManagement', $depart->id, $otherBus->id)
            ->assertSet('scheduleManagementScope', 'bus:'.$otherBus->id)
            ->assertCount('scheduleManagementRows', 1)
            ->assertSet('scheduleManagementRows.0.rendezVousPoint', 'Station essence');
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

    public function test_the_depart_export_filters_to_paid_bookings_only(): void
    {
        ['depart' => $depart, 'bus' => $bus] = $this->createUpcomingDepartWithOnePaidSeatedPassenger();

        $unpaidCustomer = Customer::create([
            'prenom' => 'moussa',
            'nom' => 'sarr',
            'phone_number' => 780000000 + random_int(1, 9999999),
        ]);
        $unpaidBusSeat = $bus->seats()->create([
            'seat_id' => Seat::query()->orderBy('number')->skip(1)->firstOrFail()->id,
            'booked' => true,
            'price' => 3550,
        ]);
        $bus->bookings()->create([
            'customer_id' => $unpaidCustomer->id,
            'depart_id' => $depart->id,
            'point_dep_id' => $depart->trajet->pointDeps()->firstOrFail()->id,
            'destination_id' => $depart->trajet->destinations()->firstOrFail()->id,
            'seat_id' => $unpaidBusSeat->id,
            'paye' => false,
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('back-office.departs.bookings-export', ['depart' => $depart->id, 'paye' => 1, 'format' => 'pdf']))
            ->assertOk()
            ->assertSee('Filtre : réservations payées')
            ->assertSee('Awa Fatou NDIAYE')
            ->assertDontSee('Moussa SARR');

        $this->actingAs($user)
            ->get(route('back-office.departs.bookings-export', ['depart' => $depart->id, 'paye' => 0, 'format' => 'pdf']))
            ->assertOk()
            ->assertSee('Filtre : réservations non payées')
            ->assertSee('Moussa SARR')
            ->assertDontSee('Awa Fatou NDIAYE');
    }

    public function test_the_bus_export_can_be_downloaded_as_a_text_file(): void
    {
        ['bus' => $bus, 'customer' => $customer] = $this->createUpcomingDepartWithOnePaidSeatedPassenger();

        $response = $this->actingAs(User::factory()->create())
            ->get(route('back-office.buses.bookings-export', ['bus' => $bus->id, 'paye' => 1, 'format' => 'text']));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/plain; charset=UTF-8');
        $this->assertStringContainsString('.txt', $response->headers->get('content-disposition'));
        $textContent = $response->streamedContent();
        $this->assertStringContainsString((string) $customer->phone_number, $textContent);
        $this->assertStringContainsString('https://globeone.site/payer/', $textContent);
    }

    public function test_the_bus_menu_exposes_the_four_filtered_export_links(): void
    {
        $this->createUpcomingDepartWithBus();

        $response = $this->actingAs(User::factory()->create())
            ->get(route('back-office.departs.index'));

        $response->assertOk();
        $response->assertSee('paye=1&amp;format=pdf', false);
        $response->assertSee('paye=0&amp;format=pdf', false);
        $response->assertSee('paye=1&amp;format=text', false);
        $response->assertSee('paye=0&amp;format=text', false);
        $response->assertSee('Payés (PDF)');
        $response->assertSee('Non payés (texte)');
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
