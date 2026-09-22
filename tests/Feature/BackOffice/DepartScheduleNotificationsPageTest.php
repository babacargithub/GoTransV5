<?php

namespace Tests\Feature\BackOffice;

use App\Livewire\BackOffice\DepartScheduleNotifications;
use App\Models\Depart;
use App\Models\Trajet;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class DepartScheduleNotificationsPageTest extends TestCase
{
    use DatabaseTransactions;

    private function createUpcomingDepart(): Depart
    {
        $trajet = Trajet::query()->firstOrFail();

        return Depart::create([
            'name' => 'DEPART RDV NOTIF '.uniqid(),
            'date' => now()->addDays(3),
            'trajet_id' => $trajet->id,
            'closed' => false,
            'locked' => false,
            'canceled' => false,
        ])->fresh();
    }

    public function test_the_page_renders_the_message_composer_for_the_depart(): void
    {
        $depart = $this->createUpcomingDepart();

        $response = $this->actingAs(User::factory()->create())
            ->get(route('back-office.departs.schedule-notifications', $depart->id));

        $response->assertOk();
        $response->assertSeeLivewire(DepartScheduleNotifications::class);
        $response->assertSee($depart->identifier(with_trajet_prefix: true));
        $response->assertSee('Message');
        $response->assertSee('&lt;Client&gt;', false);
        $response->assertSee('&lt;Arret&gt;', false);
    }

    public function test_the_message_is_prefilled_with_the_default_template(): void
    {
        $depart = $this->createUpcomingDepart();

        Livewire::actingAs(User::factory()->create())
            ->test(DepartScheduleNotifications::class, ['depart' => $depart])
            ->assertSet('message', "Bonjour <Client>\nPour départ <Depart> :\nRV <PointDep>, à <Heure>, <Arret>");
    }

    public function test_the_menu_links_to_the_schedule_notifications_page(): void
    {
        $trajet = Trajet::query()->firstOrFail();
        $depart = Depart::create([
            'name' => 'DEPART MENU LINK '.uniqid(),
            'date' => now()->addDays(3),
            'trajet_id' => $trajet->id,
            'closed' => false,
            'locked' => false,
            'canceled' => false,
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('back-office.departs.index'))
            ->assertSee(route('back-office.departs.schedule-notifications', $depart->id), false);
    }
}
