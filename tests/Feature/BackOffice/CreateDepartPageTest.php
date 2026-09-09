<?php

namespace Tests\Feature\BackOffice;

use App\Livewire\BackOffice\CreateDepart;
use App\Models\Depart;
use App\Models\Horaire;
use App\Models\Trajet;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class CreateDepartPageTest extends TestCase
{
    use DatabaseTransactions;

    private function trajetWithStops(): Trajet
    {
        return Trajet::query()
            ->has('pointDeps')
            ->has('destinations')
            ->firstOrFail();
    }

    public function test_the_depart_list_links_to_the_create_page(): void
    {
        $this->actingAs($this->createUserWithFullAccess())
            ->get(route('back-office.departs.index'))
            ->assertOk()
            ->assertSee(route('back-office.departs.create'), false);
    }

    public function test_the_page_shows_the_form_with_backend_provided_dropdown_options(): void
    {
        $trajet = $this->trajetWithStops();
        $horaire = Horaire::query()->firstOrFail();
        $vehicule = Vehicule::query()->firstOrFail();

        Livewire::actingAs($this->createUserWithFullAccess())
            ->test(CreateDepart::class)
            ->assertOk()
            ->assertSee('Trajet')
            ->assertSee('Horaire')
            ->assertSee('Visibilité')
            ->assertSee('Bus simple seulement')
            ->assertSee('Les 2')
            ->assertSee('Véhicule transport')
            ->assertSee('Template')
            ->assertSee($trajet->name)
            ->assertSee($horaire->name)
            ->assertSee($vehicule->name)
            ->assertSee('Pour tous les clients')
            ->assertSee(Carbon::today()->locale('fr')->translatedFormat('j F Y'));
    }

    public function test_the_placeholder_option_is_not_disabled_so_it_stays_visible_when_nothing_is_chosen(): void
    {
        Livewire::actingAs($this->createUserWithFullAccess())
            ->test(CreateDepart::class)
            ->assertOk()
            ->assertSeeHtml('<option value="" selected class="placeholder">Choisir un trajet</option>')
            ->assertDontSeeHtml('disabled selected class="placeholder"');
    }

    public function test_selecting_a_vehicule_prefills_the_seat_count(): void
    {
        $vehicule = Vehicule::query()->firstOrFail();

        Livewire::actingAs($this->createUserWithFullAccess())
            ->test(CreateDepart::class)
            ->set('vehiculeId', $vehicule->id)
            ->assertSet('numberOfSeats', $vehicule->nombre_place);
    }

    public function test_it_validates_required_fields(): void
    {
        Livewire::actingAs($this->createUserWithFullAccess())
            ->test(CreateDepart::class)
            ->set('busName', '')
            ->set('selectedDepartureDates', [])
            ->call('save')
            ->assertHasErrors([
                'trajetId' => 'required',
                'horaireId' => 'required',
                'vehiculeId' => 'required',
                'busName' => 'required',
                'selectedDepartureDates' => 'required',
            ])
            ->assertNoRedirect();
    }

    public function test_it_creates_one_depart_per_checked_date_and_redirects_with_a_flash_message(): void
    {
        $trajet = $this->trajetWithStops();
        $horaire = Horaire::query()->where('periode', Horaire::PERIODE_MATIN)->firstOrFail();
        $vehicule = Vehicule::query()->where('default', true)->firstOrFail();

        $firstDate = Carbon::today()->addDays(3)->toDateString();
        $secondDate = Carbon::today()->addDays(10)->toDateString();

        Livewire::actingAs($this->createUserWithFullAccess())
            ->test(CreateDepart::class)
            ->set('trajetId', $trajet->id)
            ->set('horaireId', $horaire->id)
            ->set('visibility', Depart::VISIBILITE_GP_CUSTOMERS_ONLY)
            ->set('busTypeToCreate', 'both')
            ->set('vehiculeId', $vehicule->id)
            ->set('busName', 'Bus 1')
            ->set('numberOfSeats', 57)
            ->set('ticketPrice', 3550)
            ->set('grandPublicTicketPrice', 6000)
            ->set('departNameTemplate', 'Départ <Date>')
            ->set('selectedDepartureDates', [$secondDate, $firstDate])
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('back-office.departs.index'));

        $expectedFirstName = 'Départ '.Carbon::parse($firstDate)->locale('fr')->translatedFormat('l d F');
        $firstDepart = Depart::query()->where('name', $expectedFirstName)->firstOrFail();

        $this->assertSame($trajet->id, (int) $firstDepart->trajet_id);
        $this->assertSame($horaire->id, (int) $firstDepart->horaire_id);
        $this->assertSame($firstDate, $firstDepart->date->toDateString());
        $this->assertTrue($firstDepart->buses()->where('name', 'Bus 1')->exists());
        $this->assertTrue($firstDepart->buses()->firstOrFail()->seats()->exists());

        $expectedSecondName = 'Départ '.Carbon::parse($secondDate)->locale('fr')->translatedFormat('l d F');
        $this->assertTrue(Depart::query()->where('name', $expectedSecondName)->exists());

        $this->get(route('back-office.departs.index'))
            ->assertSee('2 départs ont été créés.');
    }

    public function test_it_rejects_a_date_outside_the_scheduling_window(): void
    {
        $trajet = $this->trajetWithStops();
        $horaire = Horaire::query()->firstOrFail();
        $vehicule = Vehicule::query()->where('default', true)->firstOrFail();

        Livewire::actingAs($this->createUserWithFullAccess())
            ->test(CreateDepart::class)
            ->set('trajetId', $trajet->id)
            ->set('horaireId', $horaire->id)
            ->set('vehiculeId', $vehicule->id)
            ->set('busName', 'Bus 1')
            ->set('selectedDepartureDates', [Carbon::today()->addDays(120)->toDateString()])
            ->call('save')
            ->assertHasErrors('selectedDepartureDates.*')
            ->assertNoRedirect();
    }
}
