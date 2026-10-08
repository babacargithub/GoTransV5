<?php

namespace Tests\Feature;

use App\Models\Bus;
use App\Models\Customer;
use App\Models\Depart;
use App\Models\HeureDepart;
use App\Models\Seat;
use App\Models\Ticket;
use App\Models\Trajet;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ContactsForSmsTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return array{depart: Depart, bus: Bus, paidPhone: int, unpaidPhone: int}
     */
    private function createDepartWithOnePaidAndOneUnpaidBooking(): array
    {
        $trajet = Trajet::query()->has('pointDeps')->has('destinations')->firstOrFail();

        $depart = Depart::create([
            'name' => 'DEPART SMS '.uniqid(),
            'date' => now()->addDays(3),
            'trajet_id' => $trajet->id,
            'closed' => false,
            'locked' => false,
            'canceled' => false,
        ]);
        $bus = $depart->buses()->create([
            'name' => 'Bus SMS',
            'nombre_place' => 57,
            'ticket_price' => 3550,
            'gp_ticket_price' => 6000,
            'closed' => false,
            'agent_numbers' => '771112233/782223344',
        ]);

        $ticket = new Ticket;
        $ticket->forceFill([
            'number' => random_int(1_000_000, 9_999_999_999),
            'price' => 3550,
            'payment_method' => 'wave',
            'used' => true,
            'soldBy' => 'system',
            'soldAt' => now(),
            'expiryDate' => now()->addDays(30),
        ])->save();

        $paidPhone = 770000000 + random_int(1, 9999999);
        $unpaidPhone = 780000000 + random_int(1, 9999999);
        $seatIds = Seat::query()->orderBy('number')->take(2)->pluck('id');

        foreach ([[$paidPhone, 'awa', 'ndiaye', $ticket->id, true], [$unpaidPhone, 'moussa', 'sarr', null, false]] as $index => [$phone, $firstName, $lastName, $ticketId, $isPaid]) {
            $customer = Customer::create(['prenom' => $firstName, 'nom' => $lastName, 'phone_number' => $phone]);
            $busSeat = $bus->seats()->create(['seat_id' => $seatIds[$index], 'booked' => true, 'price' => 3550]);
            $bus->bookings()->create([
                'customer_id' => $customer->id,
                'depart_id' => $depart->id,
                'point_dep_id' => $trajet->pointDeps()->firstOrFail()->id,
                'destination_id' => $trajet->destinations()->firstOrFail()->id,
                'seat_id' => $busSeat->id,
                'ticket_id' => $ticketId,
                'paye' => $isPaid,
            ]);
        }

        return ['depart' => $depart, 'bus' => $bus, 'paidPhone' => $paidPhone, 'unpaidPhone' => $unpaidPhone];
    }

    public function test_it_returns_the_contacts_of_the_given_bus_with_the_expected_keys(): void
    {
        ['bus' => $bus, 'depart' => $depart] = $this->createDepartWithOnePaidAndOneUnpaidBooking();

        $response = $this->getJson('/api/contacts_for_sms?'.http_build_query(['entity' => 'bus', 'entity_ids' => [$bus->id]]))
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonStructure([['booking_id', 'name', 'phone', 'point_dep', 'destination', 'agent_number', 'depart_name', 'bus_name', 'seat_number', 'formatted_schedule', 'arret_bus']]);

        $response->assertJsonPath('0.bus_name', 'Bus SMS')
            ->assertJsonPath('0.agent_number', '771112233/782223344')
            ->assertJsonPath('0.depart_name', $depart->name);
    }

    public function test_the_filter_keeps_only_paid_or_unpaid_bookings(): void
    {
        ['depart' => $depart, 'paidPhone' => $paidPhone, 'unpaidPhone' => $unpaidPhone] = $this->createDepartWithOnePaidAndOneUnpaidBooking();
        $query = ['entity' => 'depart', 'entity_ids' => [$depart->id]];

        $this->getJson('/api/contacts_for_sms?'.http_build_query($query + ['filter' => 'paid']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.phone', $paidPhone);

        $this->getJson('/api/contacts_for_sms?'.http_build_query($query + ['filter' => 'unpaid']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.phone', $unpaidPhone);

        $this->getJson('/api/contacts_for_sms?'.http_build_query($query + ['filter' => 'all']))
            ->assertOk()
            ->assertJsonCount(2);
    }

    public function test_it_rejects_an_unknown_entity_or_missing_ids(): void
    {
        $this->getJson('/api/contacts_for_sms?entity=trajet&entity_ids[]=1')->assertStatus(422);
        $this->getJson('/api/contacts_for_sms?entity=bus')->assertStatus(422);
    }

    public function test_departs_for_sms_lists_upcoming_departs_soonest_first_with_their_buses(): void
    {
        ['depart' => $depart, 'bus' => $bus] = $this->createDepartWithOnePaidAndOneUnpaidBooking();

        $response = $this->getJson('/api/departs_for_sms?period=upcoming')->assertOk();

        $listedDeparts = collect($response->json());
        $listedDepart = $listedDeparts->firstWhere('id', $depart->id);
        $this->assertSame($depart->name, $listedDepart['depart_name']);
        $this->assertSame([['id' => $bus->id, 'name' => 'Bus SMS']], $listedDepart['buses']);

        $this->getJson('/api/departs_for_sms?period=upcoming&limit=1')->assertJsonCount(1);
    }

    public function test_departs_for_sms_lists_past_departs_most_recent_first_and_excludes_upcoming_ones(): void
    {
        ['depart' => $upcomingDepart] = $this->createDepartWithOnePaidAndOneUnpaidBooking();
        $pastDepart = Depart::create([
            'name' => 'DEPART PASSE '.uniqid(),
            'date' => now()->subDay(),
            'trajet_id' => $upcomingDepart->trajet_id,
            'closed' => false,
            'locked' => false,
            'canceled' => false,
        ]);

        $response = $this->getJson('/api/departs_for_sms?period=past&limit=1')->assertOk()->assertJsonCount(1);

        $this->assertSame($pastDepart->id, $response->json('0.id'));
        $this->assertNotContains($upcomingDepart->id, collect($this->getJson('/api/departs_for_sms?period=past')->json())->pluck('id')->all());
    }

    public function test_departs_for_sms_requires_a_valid_period(): void
    {
        $this->getJson('/api/departs_for_sms')->assertStatus(422);
        $this->getJson('/api/departs_for_sms?period=future')->assertStatus(422);
    }

    public function test_contacts_include_the_rendez_vous_time_and_stop_of_the_bus(): void
    {
        ['bus' => $bus, 'depart' => $depart] = $this->createDepartWithOnePaidAndOneUnpaidBooking();
        $pointDepId = $bus->bookings()->firstOrFail()->point_dep_id;
        HeureDepart::create([
            'depart_id' => $depart->id,
            'bus_id' => $bus->id,
            'point_dep_id' => $pointDepId,
            'heureDepart' => '07:45:00',
            'arretBus' => 'Devant la pharmacie',
        ]);

        $this->getJson('/api/contacts_for_sms?'.http_build_query(['entity' => 'bus', 'entity_ids' => [$bus->id]]))
            ->assertOk()
            ->assertJsonPath('0.formatted_schedule', '07:45')
            ->assertJsonPath('0.arret_bus', 'Devant la pharmacie');
    }

    public function test_contacts_without_any_schedule_get_null_time_and_the_point_de_depart_stop(): void
    {
        ['bus' => $bus] = $this->createDepartWithOnePaidAndOneUnpaidBooking();

        $this->getJson('/api/contacts_for_sms?'.http_build_query(['entity' => 'bus', 'entity_ids' => [$bus->id]]))
            ->assertOk()
            ->assertJsonPath('0.formatted_schedule', null)
            ->assertJsonPath('0.arret_bus', $bus->bookings()->firstOrFail()->point_dep->arret_bus);
    }
}
