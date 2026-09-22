<?php

namespace Tests\Feature\BackOffice;

use App\Livewire\BackOffice\VehiculeList;
use App\Models\User;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class VehiculeListPageTest extends TestCase
{
    use DatabaseTransactions;

    private function createVehicule(): Vehicule
    {
        return Vehicule::create([
            'name' => 'VEHICULE TEST '.uniqid(),
            'matricule' => 'SL-'.random_int(1000, 9999).'-T',
            'chauffeur' => 'Chauffeur Test',
            'nombre_place' => 57,
            'vehicule_type' => Vehicule::VEHICULE_TYPE_SIMPLE,
            'description' => 'Bus de test',
            'default' => false,
        ]);
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('back-office.vehicules.index'))->assertRedirect(route('login'));
    }

    public function test_the_page_lists_vehicules(): void
    {
        $vehicule = $this->createVehicule();

        Livewire::actingAs(User::factory()->create())
            ->test(VehiculeList::class)
            ->assertOk()
            ->assertSee($vehicule->name)
            ->assertSee($vehicule->matricule);
    }

    public function test_creating_a_vehicule_persists_it(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(VehiculeList::class)
            ->call('openCreateVehicule')
            ->assertSet('showVehiculeModal', true)
            ->set('vehiculeName', 'Bus Climatisé Neuf')
            ->set('vehiculeDriverName', 'Modou')
            ->set('vehiculeNumberOfSeats', 63)
            ->set('vehiculeType', Vehicule::VEHICULE_TYPE_CLIMATISE)
            ->call('saveVehicule')
            ->assertHasNoErrors()
            ->assertSet('showVehiculeModal', false);

        $this->assertDatabaseHas('vehicules', [
            'name' => 'Bus Climatisé Neuf',
            'nombre_place' => 63,
            'vehicule_type' => Vehicule::VEHICULE_TYPE_CLIMATISE,
        ]);
    }

    public function test_creating_requires_a_name_and_seat_count(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(VehiculeList::class)
            ->call('openCreateVehicule')
            ->set('vehiculeName', '')
            ->set('vehiculeNumberOfSeats', null)
            ->call('saveVehicule')
            ->assertHasErrors(['vehiculeName', 'vehiculeNumberOfSeats']);
    }

    public function test_marking_a_vehicule_default_unsets_the_others(): void
    {
        $existingDefault = $this->createVehicule();
        $existingDefault->update(['default' => true]);
        $vehicule = $this->createVehicule();

        Livewire::actingAs(User::factory()->create())
            ->test(VehiculeList::class)
            ->call('openEditVehicule', $vehicule->id)
            ->set('vehiculeIsDefault', true)
            ->call('saveVehicule')
            ->assertHasNoErrors();

        $this->assertTrue($vehicule->fresh()->default);
        $this->assertFalse($existingDefault->fresh()->default);
    }

    public function test_deleting_a_vehicule_removes_it(): void
    {
        $vehicule = $this->createVehicule();

        Livewire::actingAs(User::factory()->create())
            ->test(VehiculeList::class)
            ->call('askToDeleteVehicule', $vehicule->id)
            ->assertSet('showDeleteVehiculeModal', true)
            ->call('confirmDeleteVehicule');

        $this->assertDatabaseMissing('vehicules', ['id' => $vehicule->id]);
    }
}
