<?php

namespace Tests\Feature\BackOffice;

use App\Livewire\BackOffice\HoraireList;
use App\Models\Horaire;
use App\Models\Trajet;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class HoraireListPageTest extends TestCase
{
    use DatabaseTransactions;

    private function createHoraire(): Horaire
    {
        $trajet = Trajet::query()->firstOrFail();

        return Horaire::create([
            'trajet_id' => $trajet->id,
            'name' => 'HORAIRE TEST '.uniqid(),
            'bus_leave_time' => '05:30',
            'periode' => Horaire::PERIODE_MATIN,
        ]);
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('back-office.horaires.index'))->assertRedirect(route('login'));
    }

    public function test_the_page_lists_the_horaires_with_their_trajet(): void
    {
        $horaire = $this->createHoraire();

        Livewire::actingAs(User::factory()->create())
            ->test(HoraireList::class)
            ->assertOk()
            ->assertSee($horaire->name)
            ->assertSee($horaire->trajet->name);
    }

    public function test_editing_a_horaire_updates_it(): void
    {
        $horaire = $this->createHoraire();

        Livewire::actingAs(User::factory()->create())
            ->test(HoraireList::class)
            ->call('openEditHoraire', $horaire->id)
            ->assertSet('showEditHoraireModal', true)
            ->assertSet('editHoraireName', $horaire->name)
            ->set('editHoraireName', 'Horaire Modifié')
            ->set('editHoraireBusLeaveTime', '21:00')
            ->set('editHorairePeriode', Horaire::PERIODE_NUIT)
            ->call('saveEditedHoraire')
            ->assertHasNoErrors()
            ->assertSet('showEditHoraireModal', false);

        $freshHoraire = $horaire->fresh();
        $this->assertSame('Horaire Modifié', $freshHoraire->name);
        $this->assertSame('21:00', $freshHoraire->bus_leave_time->format('H:i'));
        $this->assertSame(Horaire::PERIODE_NUIT, $freshHoraire->periode);
    }

    public function test_editing_requires_a_valid_periode(): void
    {
        $horaire = $this->createHoraire();

        Livewire::actingAs(User::factory()->create())
            ->test(HoraireList::class)
            ->call('openEditHoraire', $horaire->id)
            ->set('editHorairePeriode', 'invalide')
            ->call('saveEditedHoraire')
            ->assertHasErrors(['editHorairePeriode']);
    }

    public function test_deleting_a_horaire_removes_it(): void
    {
        $horaire = $this->createHoraire();

        Livewire::actingAs(User::factory()->create())
            ->test(HoraireList::class)
            ->call('askToDeleteHoraire', $horaire->id)
            ->assertSet('showDeleteHoraireModal', true)
            ->call('confirmDeleteHoraire')
            ->assertSet('showDeleteHoraireModal', false);

        $this->assertDatabaseMissing('horaires', ['id' => $horaire->id]);
    }
}
