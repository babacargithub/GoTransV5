<?php

namespace Tests\Feature\BackOffice;

use App\Livewire\BackOffice\PointDepList;
use App\Models\PointDep;
use App\Models\Trajet;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class PointDepListPageTest extends TestCase
{
    use DatabaseTransactions;

    private function createPointDep(): PointDep
    {
        $trajet = Trajet::query()->firstOrFail();

        return PointDep::create([
            'name' => 'POINT DEP TEST '.uniqid(),
            'trajet_id' => $trajet->id,
            'heure_point_dep' => '06:30',
            'heure_point_dep_soir' => '15:30',
            'arret_bus' => 'Station Test',
            'position' => 999,
            'disabled' => false,
        ]);
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('back-office.point-deps.index'))->assertRedirect(route('login'));
    }

    public function test_the_page_lists_the_point_deps_with_their_trajet(): void
    {
        $pointDep = $this->createPointDep();

        Livewire::actingAs(User::factory()->create())
            ->test(PointDepList::class)
            ->assertOk()
            ->assertSee($pointDep->name)
            ->assertSee($pointDep->trajet->name);
    }

    public function test_editing_a_point_dep_updates_it_through_the_legacy_controller(): void
    {
        $pointDep = $this->createPointDep();

        Livewire::actingAs(User::factory()->create())
            ->test(PointDepList::class)
            ->call('openEditPointDep', $pointDep->id)
            ->assertSet('showEditPointDepModal', true)
            ->assertSet('editPointDepName', $pointDep->name)
            ->set('editPointDepName', 'Point Dep Modifié')
            ->set('editPointDepMorningSchedule', '07:15')
            ->set('editPointDepBusStop', 'Nouvel arrêt')
            ->call('saveEditedPointDep')
            ->assertHasNoErrors()
            ->assertSet('showEditPointDepModal', false);

        $this->assertDatabaseHas('point_deps', [
            'id' => $pointDep->id,
            'name' => 'Point Dep Modifié',
            'arret_bus' => 'Nouvel arrêt',
        ]);
        $this->assertSame('07:15', $pointDep->fresh()->heure_point_dep->format('H:i'));
    }

    public function test_editing_requires_a_name(): void
    {
        $pointDep = $this->createPointDep();

        Livewire::actingAs(User::factory()->create())
            ->test(PointDepList::class)
            ->call('openEditPointDep', $pointDep->id)
            ->set('editPointDepName', '')
            ->call('saveEditedPointDep')
            ->assertHasErrors(['editPointDepName']);
    }

    public function test_disabling_a_point_dep_toggles_its_flag(): void
    {
        $pointDep = $this->createPointDep();

        Livewire::actingAs(User::factory()->create())
            ->test(PointDepList::class)
            ->call('togglePointDepDisabled', $pointDep->id);

        $this->assertTrue($pointDep->fresh()->disabled);

        Livewire::actingAs(User::factory()->create())
            ->test(PointDepList::class)
            ->call('togglePointDepDisabled', $pointDep->id);

        $this->assertFalse($pointDep->fresh()->disabled);
    }

    public function test_deleting_a_point_dep_removes_it(): void
    {
        $pointDep = $this->createPointDep();

        Livewire::actingAs(User::factory()->create())
            ->test(PointDepList::class)
            ->call('askToDeletePointDep', $pointDep->id)
            ->assertSet('showDeletePointDepModal', true)
            ->call('confirmDeletePointDep')
            ->assertSet('showDeletePointDepModal', false);

        $this->assertDatabaseMissing('point_deps', ['id' => $pointDep->id]);
    }
}
