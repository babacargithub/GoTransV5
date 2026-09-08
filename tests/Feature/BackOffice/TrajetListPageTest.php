<?php

namespace Tests\Feature\BackOffice;

use App\Livewire\BackOffice\TrajetList;
use App\Models\Destination;
use App\Models\PointDep;
use App\Models\Trajet;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class TrajetListPageTest extends TestCase
{
    use DatabaseTransactions;

    private function createTrajet(): Trajet
    {
        return Trajet::create([
            'name' => 'TRAJET TEST '.uniqid(),
            'public_name' => 'Trajet Test',
            'departure_city' => 'DAKAR',
            'arrival_city' => 'SAINT-LOUIS',
        ]);
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('back-office.trajets.index'))->assertRedirect(route('login'));
    }

    public function test_the_page_lists_trajets_with_point_dep_and_destination_counts(): void
    {
        $trajet = $this->createTrajet();
        PointDep::create([
            'name' => 'PD '.uniqid(),
            'trajet_id' => $trajet->id,
            'heure_point_dep' => '06:00',
            'heure_point_dep_soir' => '15:00',
            'arret_bus' => 'Gare',
            'position' => 1,
        ]);
        $trajet->destinations()->create(['name' => 'Destination Test']);

        Livewire::actingAs(User::factory()->create())
            ->test(TrajetList::class)
            ->assertOk()
            ->assertSee($trajet->name)
            ->assertSee('1 point(s) de départ')
            ->assertSee('1 destination(s)');
    }

    public function test_editing_a_trajet_updates_it_through_the_legacy_controller(): void
    {
        $trajet = $this->createTrajet();

        Livewire::actingAs(User::factory()->create())
            ->test(TrajetList::class)
            ->call('openEditTrajet', $trajet->id)
            ->assertSet('showEditTrajetModal', true)
            ->set('editTrajetName', 'Trajet Renommé '.uniqid())
            ->set('editTrajetPublicName', 'Nouveau nom public')
            ->call('saveEditedTrajet')
            ->assertHasNoErrors()
            ->assertSet('showEditTrajetModal', false);

        $this->assertSame('Nouveau nom public', $trajet->fresh()->public_name);
    }

    public function test_deleting_a_trajet_removes_it(): void
    {
        $trajet = $this->createTrajet();

        Livewire::actingAs(User::factory()->create())
            ->test(TrajetList::class)
            ->call('askToDeleteTrajet', $trajet->id)
            ->assertSet('showDeleteTrajetModal', true)
            ->call('confirmDeleteTrajet');

        $this->assertDatabaseMissing('trajets', ['id' => $trajet->id]);
    }

    public function test_the_point_deps_dialog_adds_a_point_dep_to_the_trajet(): void
    {
        $trajet = $this->createTrajet();

        Livewire::actingAs(User::factory()->create())
            ->test(TrajetList::class)
            ->call('openPointDepsDialog', $trajet->id)
            ->assertSet('showPointDepsDialog', true)
            ->call('startAddingPointDep')
            ->set('pointDepForm.name', 'Nouveau point')
            ->set('pointDepForm.morningSchedule', '07:00')
            ->set('pointDepForm.eveningSchedule', '16:00')
            ->set('pointDepForm.busStop', 'Terminus')
            ->call('savePointDepForm')
            ->assertHasNoErrors()
            ->assertSet('showPointDepForm', false);

        $this->assertDatabaseHas('point_deps', [
            'trajet_id' => $trajet->id,
            'name' => 'Nouveau point',
            'arret_bus' => 'Terminus',
        ]);
    }

    public function test_the_point_deps_dialog_edits_and_deletes_a_point_dep(): void
    {
        $trajet = $this->createTrajet();
        $pointDep = PointDep::create([
            'name' => 'PD Edit',
            'trajet_id' => $trajet->id,
            'heure_point_dep' => '06:00',
            'heure_point_dep_soir' => '15:00',
            'arret_bus' => 'Gare',
            'position' => 1,
        ]);

        $component = Livewire::actingAs(User::factory()->create())
            ->test(TrajetList::class)
            ->call('openPointDepsDialog', $trajet->id)
            ->call('startEditingPointDep', $pointDep->id)
            ->assertSet('pointDepForm.name', 'PD Edit')
            ->set('pointDepForm.name', 'PD Modifié')
            ->call('savePointDepForm')
            ->assertHasNoErrors();

        $this->assertSame('PD Modifié', $pointDep->fresh()->name);

        $component->call('deletePointDep', $pointDep->id);

        $this->assertDatabaseMissing('point_deps', ['id' => $pointDep->id]);
    }

    public function test_the_destinations_dialog_adds_edits_and_deletes_a_destination(): void
    {
        $trajet = $this->createTrajet();

        $component = Livewire::actingAs(User::factory()->create())
            ->test(TrajetList::class)
            ->call('openDestinationsDialog', $trajet->id)
            ->assertSet('showDestinationsDialog', true)
            ->call('startAddingDestination')
            ->set('destinationForm.name', 'Thiès')
            ->set('destinationForm.tarif', 3600)
            ->call('saveDestinationForm')
            ->assertHasNoErrors()
            ->assertSet('showDestinationForm', false);

        $destination = Destination::where('trajet_id', $trajet->id)->firstOrFail();
        $this->assertSame('Thiès', $destination->name);

        $component->call('startEditingDestination', $destination->id)
            ->set('destinationForm.name', 'Thiès Ville')
            ->call('saveDestinationForm')
            ->assertHasNoErrors();

        $this->assertSame('Thiès Ville', $destination->fresh()->name);

        $component->call('deleteDestination', $destination->id);

        $this->assertDatabaseMissing('destinations', ['id' => $destination->id]);
    }
}
