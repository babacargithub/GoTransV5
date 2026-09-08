<?php

namespace Tests\Feature\BackOffice;

use App\Livewire\BackOffice\EditDepart;
use App\Models\Depart;
use App\Models\Horaire;
use App\Models\Trajet;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class EditDepartPageTest extends TestCase
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
            'date' => Carbon::today()->addDays(5)->setTime(8, 30),
            'trajet_id' => $trajet->id,
            'horaire_id' => Horaire::query()->where('periode', Horaire::PERIODE_MATIN)->firstOrFail()->id,
            'visibilite' => Depart::VISIBILITE_ALL_CUSTOMERS,
            'closed' => false,
            'locked' => false,
            'canceled' => false,
        ]);
    }

    public function test_the_edit_button_on_the_depart_list_links_to_the_page(): void
    {
        $depart = $this->createUpcomingDepart();

        $this->actingAs(User::factory()->create())
            ->get(route('back-office.departs.index'))
            ->assertOk()
            ->assertSee(route('back-office.departs.edit', $depart->id), false);
    }

    public function test_the_page_prefills_the_form_with_the_current_depart_values(): void
    {
        $depart = $this->createUpcomingDepart();

        Livewire::actingAs(User::factory()->create())
            ->test(EditDepart::class, ['depart' => $depart])
            ->assertOk()
            ->assertSet('departName', $depart->getRawOriginal('name'))
            ->assertSet('departureDate', $depart->date->format('Y-m-d'))
            ->assertSet('departureTime', $depart->date->format('H:i'))
            ->assertSet('horaireId', $depart->horaire_id)
            ->assertSet('visibility', Depart::VISIBILITE_ALL_CUSTOMERS)
            ->assertSee($depart->trajet->name);
    }

    public function test_it_updates_the_depart_date_and_time_and_redirects_with_a_flash_message(): void
    {
        $depart = $this->createUpcomingDepart();
        $newDate = Carbon::today()->addDays(12)->toDateString();
        $eveningHoraire = Horaire::query()->where('periode', Horaire::PERIODE_NUIT)->firstOrFail();

        Livewire::actingAs(User::factory()->create())
            ->test(EditDepart::class, ['depart' => $depart])
            ->set('departName', 'Départ modifié')
            ->set('departureDate', $newDate)
            ->set('departureTime', '19:45')
            ->set('horaireId', $eveningHoraire->id)
            ->set('visibility', Depart::VISIBILITE_GP_CUSTOMERS_ONLY)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('back-office.departs.index'));

        $depart->refresh();

        $this->assertSame('Départ modifié', $depart->getRawOriginal('name'));
        $this->assertSame($newDate.' 19:45:00', $depart->date->format('Y-m-d H:i:s'));
        $this->assertSame($eveningHoraire->id, (int) $depart->horaire_id);
        $this->assertSame(Depart::VISIBILITE_GP_CUSTOMERS_ONLY, (int) $depart->visibilite);

        $this->get(route('back-office.departs.index'))
            ->assertSee('Le départ « Départ modifié » a été modifié.');
    }

    public function test_it_validates_required_fields(): void
    {
        $depart = $this->createUpcomingDepart();

        Livewire::actingAs(User::factory()->create())
            ->test(EditDepart::class, ['depart' => $depart])
            ->set('departName', '')
            ->set('departureDate', '')
            ->set('departureTime', '')
            ->call('save')
            ->assertHasErrors([
                'departName' => 'required',
                'departureDate' => 'required',
                'departureTime' => 'required',
            ])
            ->assertNoRedirect();
    }

    public function test_the_legacy_json_api_can_still_update_a_depart(): void
    {
        $depart = $this->createUpcomingDepart();

        Sanctum::actingAs(User::factory()->create());

        $this->putJson("/api/departs/{$depart->id}", [
            'name' => 'Départ via API',
            'visibilite' => Depart::VISIBILITE_STAFF_ONLY,
        ])->assertOk();

        $depart->refresh();

        $this->assertSame('Départ via API', $depart->getRawOriginal('name'));
        $this->assertSame(Depart::VISIBILITE_STAFF_ONLY, (int) $depart->visibilite);
    }
}
