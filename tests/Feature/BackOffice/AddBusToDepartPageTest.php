<?php

namespace Tests\Feature\BackOffice;

use App\Livewire\BackOffice\AddBusToDepart;
use App\Models\Depart;
use App\Models\Horaire;
use App\Models\Itinerary;
use App\Models\Trajet;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class AddBusToDepartPageTest extends TestCase
{
    use DatabaseTransactions;

    private function createUpcomingDepart(): Depart
    {
        $trajet = Trajet::query()
            ->has('pointDeps')
            ->has('destinations')
            ->firstOrFail();

        return Depart::create([
            'name' => 'DEPART TEST '.uniqid(),
            'date' => now()->addDays(3),
            'trajet_id' => $trajet->id,
            'horaire_id' => Horaire::query()->where('periode', Horaire::PERIODE_MATIN)->firstOrFail()->id,
            'closed' => false,
            'locked' => false,
            'canceled' => false,
        ]);
    }

    public function test_the_add_bus_button_on_the_depart_list_links_to_the_page(): void
    {
        $depart = $this->createUpcomingDepart();
        $depart->buses()->create([
            'name' => 'Bus Existant',
            'nombre_place' => 57,
            'ticket_price' => 3550,
            'gp_ticket_price' => 6000,
            'closed' => false,
        ]);

        $this->actingAs($this->createUserWithFullAccess())
            ->get(route('back-office.departs.index'))
            ->assertOk()
            ->assertSee(route('back-office.departs.add-bus', $depart->id), false);
    }

    public function test_the_page_shows_the_form_with_backend_provided_dropdown_options(): void
    {
        $depart = $this->createUpcomingDepart();
        $vehicule = Vehicule::query()->firstOrFail();
        $itinerary = Itinerary::query()->firstOrFail();

        Livewire::actingAs($this->createUserWithFullAccess())
            ->test(AddBusToDepart::class, ['depart' => $depart])
            ->assertOk()
            ->assertSee('Véhicule transport')
            ->assertSee('Nom du Bus')
            ->assertSee('Prix Ticket GP')
            ->assertSee('Numéro convoyeur')
            ->assertSee('Itinéraire')
            ->assertSee('Visibilité')
            ->assertSee($vehicule->name.' - '.$vehicule->nombre_place.' places')
            ->assertSee($itinerary->name)
            ->assertSee('Pour tous les clients');
    }

    public function test_selecting_a_vehicule_prefills_the_seat_count_and_ticket_price(): void
    {
        $depart = $this->createUpcomingDepart();
        $vehicule = Vehicule::query()->firstOrFail();

        Livewire::actingAs($this->createUserWithFullAccess())
            ->test(AddBusToDepart::class, ['depart' => $depart])
            ->set('vehiculeId', $vehicule->id)
            ->assertSet('numberOfSeats', $vehicule->nombre_place)
            ->assertSet('ticketPrice', (float) ($vehicule->climatise ? 4000 : 3550));
    }

    public function test_it_creates_a_bus_on_the_depart_and_redirects_with_a_flash_message(): void
    {
        $depart = $this->createUpcomingDepart();
        $defaultVehicule = Vehicule::query()->where('default', true)->firstOrFail();

        Livewire::actingAs($this->createUserWithFullAccess())
            ->test(AddBusToDepart::class, ['depart' => $depart])
            ->set('vehiculeId', $defaultVehicule->id)
            ->set('name', 'Bus Nouveau')
            ->set('numberOfSeats', 57)
            ->set('ticketPrice', 3550)
            ->set('gpTicketPrice', 6000)
            ->set('visibility', Depart::VISIBILITE_GP_CUSTOMERS_ONLY)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('back-office.departs.index'));

        $bus = $depart->buses()->where('name', 'Bus Nouveau')->firstOrFail();

        $this->assertSame(3550.0, (float) $bus->ticket_price);
        $this->assertSame(6000.0, (float) $bus->gp_ticket_price);
        $this->assertSame(Depart::VISIBILITE_GP_CUSTOMERS_ONLY, (int) $bus->visibilite);
        $this->assertTrue($bus->seats()->exists());

        $this->get(route('back-office.departs.index'))
            ->assertSee('Le bus « Bus Nouveau » a été ajouté au départ.');
    }

    public function test_it_rejects_a_bus_name_already_used_on_the_same_depart(): void
    {
        $depart = $this->createUpcomingDepart();
        $depart->buses()->create([
            'name' => 'Bus Doublon',
            'nombre_place' => 57,
            'ticket_price' => 3550,
            'gp_ticket_price' => 6000,
            'closed' => false,
        ]);
        $defaultVehicule = Vehicule::query()->where('default', true)->firstOrFail();

        Livewire::actingAs($this->createUserWithFullAccess())
            ->test(AddBusToDepart::class, ['depart' => $depart])
            ->set('vehiculeId', $defaultVehicule->id)
            ->set('name', 'Bus Doublon')
            ->set('numberOfSeats', 57)
            ->set('ticketPrice', 3550)
            ->call('save')
            ->assertHasErrors('name')
            ->assertNoRedirect();

        $this->assertSame(1, $depart->buses()->where('name', 'Bus Doublon')->count());
    }

    public function test_it_validates_required_fields(): void
    {
        $depart = $this->createUpcomingDepart();

        Livewire::actingAs($this->createUserWithFullAccess())
            ->test(AddBusToDepart::class, ['depart' => $depart])
            ->call('save')
            ->assertHasErrors([
                'vehiculeId' => 'required',
                'name' => 'required',
                'numberOfSeats' => 'required',
                'ticketPrice' => 'required',
            ]);
    }

    public function test_the_legacy_json_api_can_still_add_a_bus_without_a_visibility(): void
    {
        $depart = $this->createUpcomingDepart();
        $defaultVehicule = Vehicule::query()->where('default', true)->firstOrFail();

        Sanctum::actingAs($this->createUserWithFullAccess());

        $this->postJson("/api/departs/{$depart->id}/add_bus", [
            'name' => 'Bus API',
            'ticket_price' => 3550,
            'nombre_place' => 57,
            'vehicule_id' => $defaultVehicule->id,
        ])->assertCreated();

        $this->assertSame(
            Depart::VISIBILITE_ALL_CUSTOMERS,
            (int) $depart->buses()->where('name', 'Bus API')->firstOrFail()->visibilite,
        );
    }
}
