<?php

namespace Tests\Feature\BackOffice;

use App\Livewire\BackOffice\EditBus;
use App\Models\Bus;
use App\Models\Depart;
use App\Models\Itinerary;
use App\Models\Trajet;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class EditBusPageTest extends TestCase
{
    use DatabaseTransactions;

    private function createBus(): Bus
    {
        $trajet = Trajet::query()->firstOrFail();

        $depart = Depart::create([
            'name' => 'DEPART BUS EDIT '.uniqid(),
            'date' => now()->addDays(4),
            'trajet_id' => $trajet->id,
            'closed' => false,
            'locked' => false,
            'canceled' => false,
        ]);

        return $depart->buses()->create([
            'name' => 'Bus Edit Un',
            'nombre_place' => 57,
            'ticket_price' => 3550,
            'gp_ticket_price' => 6000,
            'visibilite' => Depart::VISIBILITE_ALL_CUSTOMERS,
            'itinerary_id' => Itinerary::query()->firstOrFail()->id,
            'agent_numbers' => '770000000',
            'closed' => false,
        ]);
    }

    public function test_the_modifier_infos_bus_menu_item_links_to_the_page(): void
    {
        $bus = $this->createBus();

        $this->actingAs($this->createUserWithFullAccess())
            ->get(route('back-office.departs.index'))
            ->assertOk()
            ->assertSee(route('back-office.buses.edit', $bus->id), false);
    }

    public function test_the_page_prefills_the_form_with_the_current_bus_values(): void
    {
        $bus = $this->createBus();

        Livewire::actingAs($this->createUserWithFullAccess())
            ->test(EditBus::class, ['bus' => $bus])
            ->assertOk()
            ->assertSet('busName', 'Bus Edit Un')
            ->assertSet('numberOfSeats', 57)
            ->assertSet('ticketPrice', 3550.0)
            ->assertSet('gpTicketPrice', 6000.0)
            ->assertSet('agentNumbers', '770000000')
            ->assertSet('itineraryId', $bus->itinerary_id)
            ->assertSet('visibility', Depart::VISIBILITE_ALL_CUSTOMERS);
    }

    public function test_saving_updates_the_bus_through_the_legacy_controller(): void
    {
        $bus = $this->createBus();
        $otherItinerary = Itinerary::query()->firstOrFail();

        Livewire::actingAs($this->createUserWithFullAccess())
            ->test(EditBus::class, ['bus' => $bus])
            ->set('busName', 'Bus Edit Modifié')
            ->set('numberOfSeats', 63)
            ->set('ticketPrice', 4000)
            ->set('gpTicketPrice', 6500)
            ->set('agentNumbers', '771111111 / 782222222')
            ->set('itineraryId', $otherItinerary->id)
            ->set('visibility', Depart::VISIBILITE_STAFF_ONLY)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('back-office.departs.index'));

        $this->assertDatabaseHas('buses', [
            'id' => $bus->id,
            'name' => 'Bus Edit Modifié',
            'nombre_place' => 63,
            'ticket_price' => 4000,
            'gp_ticket_price' => 6500,
            'agent_numbers' => '771111111 / 782222222',
            'visibilite' => Depart::VISIBILITE_STAFF_ONLY,
        ]);
    }

    public function test_saving_requires_the_name_and_the_itinerary(): void
    {
        $bus = $this->createBus();

        Livewire::actingAs($this->createUserWithFullAccess())
            ->test(EditBus::class, ['bus' => $bus])
            ->set('busName', '')
            ->set('itineraryId', null)
            ->call('save')
            ->assertHasErrors(['busName', 'itineraryId']);

        $this->assertDatabaseHas('buses', ['id' => $bus->id, 'name' => 'Bus Edit Un']);
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $bus = $this->createBus();

        $this->get(route('back-office.buses.edit', $bus->id))->assertRedirect(route('login'));
    }
}
