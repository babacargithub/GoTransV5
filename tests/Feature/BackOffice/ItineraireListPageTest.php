<?php

namespace Tests\Feature\BackOffice;

use App\Livewire\BackOffice\ItineraireList;
use App\Models\Itinerary;
use App\Models\Trajet;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class ItineraireListPageTest extends TestCase
{
    use DatabaseTransactions;

    private function createItineraire(): Itinerary
    {
        $trajet = Trajet::query()->has('pointDeps')->firstOrFail();
        $pointDepIds = $trajet->pointDeps()->pluck('id')->take(2)->all();

        return Itinerary::create([
            'name' => 'ITINERAIRE TEST '.uniqid(),
            'trajet_id' => $trajet->id,
            'point_deps' => $pointDepIds,
            'disabled' => false,
        ]);
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('back-office.itineraires.index'))->assertRedirect(route('login'));
    }

    public function test_the_page_lists_the_itineraires_with_their_trajet_and_point_dep_count(): void
    {
        $itineraire = $this->createItineraire();

        Livewire::actingAs(User::factory()->create())
            ->test(ItineraireList::class)
            ->assertOk()
            ->assertSee($itineraire->name)
            ->assertSee($itineraire->trajet->name)
            ->assertSee('2');
    }

    public function test_editing_an_itineraire_updates_it_through_the_legacy_controller(): void
    {
        $itineraire = $this->createItineraire();
        $trajet = $itineraire->trajet;
        $newPointDepIds = $trajet->pointDeps()->pluck('id')->take(3)->map(fn ($id) => (int) $id)->all();

        Livewire::actingAs(User::factory()->create())
            ->test(ItineraireList::class)
            ->call('openEditItineraire', $itineraire->id)
            ->assertSet('showEditItineraireModal', true)
            ->assertSet('editItineraireName', $itineraire->name)
            ->set('editItineraireName', 'Itinéraire Modifié '.uniqid())
            ->set('editItinerairePointDepIds', $newPointDepIds)
            ->call('saveEditedItineraire')
            ->assertHasNoErrors()
            ->assertSet('showEditItineraireModal', false);

        $this->assertEqualsCanonicalizing($newPointDepIds, $itineraire->fresh()->point_deps);
    }

    public function test_editing_requires_at_least_one_point_dep(): void
    {
        $itineraire = $this->createItineraire();

        Livewire::actingAs(User::factory()->create())
            ->test(ItineraireList::class)
            ->call('openEditItineraire', $itineraire->id)
            ->set('editItinerairePointDepIds', [])
            ->call('saveEditedItineraire')
            ->assertHasErrors(['editItinerairePointDepIds']);
    }

    public function test_disabling_an_itineraire_toggles_its_flag(): void
    {
        $itineraire = $this->createItineraire();

        Livewire::actingAs(User::factory()->create())
            ->test(ItineraireList::class)
            ->call('toggleItineraireDisabled', $itineraire->id);

        $this->assertTrue($itineraire->fresh()->disabled);
    }

    public function test_deleting_an_itineraire_removes_it(): void
    {
        $itineraire = $this->createItineraire();

        Livewire::actingAs(User::factory()->create())
            ->test(ItineraireList::class)
            ->call('askToDeleteItineraire', $itineraire->id)
            ->assertSet('showDeleteItineraireModal', true)
            ->call('confirmDeleteItineraire')
            ->assertSet('showDeleteItineraireModal', false);

        $this->assertDatabaseMissing('itineraries', ['id' => $itineraire->id]);
    }
}
