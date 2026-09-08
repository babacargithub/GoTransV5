<?php

namespace Tests\Feature\BackOffice;

use App\Livewire\BackOffice\DepartStatsSidebar;
use App\Models\Depart;
use App\Models\Trajet;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class DepartStatsSidebarTest extends TestCase
{
    use DatabaseTransactions;

    private function createUpcomingDepartWithBus(): Depart
    {
        $trajet = Trajet::query()->firstOrFail();

        $depart = Depart::create([
            'name' => 'SIDEBAR DEPART '.uniqid(),
            'date' => now()->addDays(2),
            'trajet_id' => $trajet->id,
            'closed' => false,
            'locked' => false,
            'canceled' => false,
        ]);

        $depart->buses()->create([
            'name' => 'Bus Sidebar',
            'nombre_place' => 57,
            'ticket_price' => 3550,
            'gp_ticket_price' => 6000,
            'closed' => false,
        ]);

        return $depart->fresh();
    }

    public function test_the_sidebar_lists_upcoming_departs_and_their_buses(): void
    {
        $depart = $this->createUpcomingDepartWithBus();

        Livewire::actingAs(User::factory()->create())
            ->test(DepartStatsSidebar::class)
            ->assertOk()
            ->assertSee($depart->trajet->code.'-'.$depart->name)
            ->assertSee('Bus Sidebar')
            ->assertSee('réservation(s) au total');
    }

    public function test_the_sidebar_can_be_refreshed(): void
    {
        $this->createUpcomingDepartWithBus();

        Livewire::actingAs(User::factory()->create())
            ->test(DepartStatsSidebar::class)
            ->call('refreshDepartStats')
            ->assertOk();
    }

    public function test_the_sidebar_is_rendered_on_the_back_office_pages(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('back-office.departs.index'))
            ->assertOk()
            ->assertSeeLivewire(DepartStatsSidebar::class);
    }
}
