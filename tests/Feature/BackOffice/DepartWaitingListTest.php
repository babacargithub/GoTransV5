<?php

namespace Tests\Feature\BackOffice;

use App\Livewire\BackOffice\DepartList;
use App\Models\Customer;
use App\Models\Depart;
use App\Models\Trajet;
use App\Models\WaitingCustomer;
use App\Services\WaitingCustomerService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class DepartWaitingListTest extends TestCase
{
    use DatabaseTransactions;

    private function createDepart(): Depart
    {
        return Depart::create([
            'name' => 'DEPART ATTENTE '.uniqid(),
            'date' => now()->addDay()->format('Y-m-d H:i:s'),
            'trajet_id' => Trajet::query()->firstOrFail()->id,
            'closed' => false,
            'locked' => false,
            'canceled' => false,
        ]);
    }

    public function test_the_waiting_list_dialog_shows_the_customers_of_the_depart_with_their_booking_time(): void
    {
        $depart = $this->createDepart();
        $otherDepart = $this->createDepart();
        $bus = $depart->buses()->create([
            'name' => 'Bus Attente',
            'nombre_place' => 57,
            'ticket_price' => 8000,
            'gp_ticket_price' => 6000,
            'closed' => false,
        ]);
        $waitingCustomer = Customer::create(['prenom' => 'Awa', 'nom' => 'Diallo', 'phone_number' => '771110001']);
        $customerOfOtherDepart = Customer::create(['prenom' => 'Moussa', 'nom' => 'Sarr', 'phone_number' => '771110002']);

        WaitingCustomer::create([
            'customer_id' => $waitingCustomer->id,
            'depart_id' => $depart->id,
            'bus_id' => $bus->id,
            'data' => ['reason' => WaitingCustomerService::REASON_BUS_FULL],
        ])->forceFill(['created_at' => '2026-11-20 14:35:00'])->save();
        WaitingCustomer::create([
            'customer_id' => $customerOfOtherDepart->id,
            'depart_id' => $otherDepart->id,
            'bus_id' => null,
            'data' => [],
        ]);

        Livewire::actingAs($this->createUserWithFullAccess())
            ->test(DepartList::class)
            ->call('openWaitingCustomers', $depart->id)
            ->assertSet('showWaitingCustomersModal', true)
            ->assertSee($waitingCustomer->full_name)
            ->assertSee($waitingCustomer->phone_number)
            ->assertSee('Bus Attente')
            ->assertSee('Bus complet')
            ->assertSee('20/11/2026 à 14:35')
            ->assertDontSee($customerOfOtherDepart->full_name)
            ->call('closeWaitingCustomers')
            ->assertSet('showWaitingCustomersModal', false);
    }

    public function test_the_waiting_list_dialog_says_so_when_nobody_is_waiting(): void
    {
        $depart = $this->createDepart();

        Livewire::actingAs($this->createUserWithFullAccess())
            ->test(DepartList::class)
            ->call('openWaitingCustomers', $depart->id)
            ->assertSee("Aucun client en liste d'attente pour ce départ.", false);
    }
}
