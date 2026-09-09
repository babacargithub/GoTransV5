<?php

namespace Tests\Feature;

use App\Livewire\Website\BookingGroupShow;
use App\Manager\BookingManager;
use App\Models\Booking;
use App\Models\Bus;
use App\Models\Customer;
use App\Models\Depart;
use App\Models\Destination;
use App\Models\PointDep;
use App\Models\Ticket;
use App\Models\Trajet;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class BookingGroupPageTest extends TestCase
{
    use DatabaseTransactions;

    private function websiteUrl(string $path = '/'): string
    {
        return 'http://'.config('app.public_website_domain').$path;
    }

    /**
     * @return array{uuid: string, groupId: int, bookings: Collection<int, Booking>, depart: Depart}
     */
    private function createBookingGroup(bool $paid = false, bool $departPassed = false): array
    {
        $trajet = Trajet::create([
            'name' => 'Caravane '.uniqid(),
            'departure_city' => 'DAKAR',
            'arrival_city' => 'SAINT-LOUIS',
        ]);

        $depart = Depart::create([
            'name' => 'DEPART WEB '.uniqid(),
            'date' => $departPassed ? now()->subDays(2) : now()->addDays(5),
            'trajet_id' => $trajet->id,
            'visibilite' => Depart::VISIBILITE_ALL_CUSTOMERS,
            'closed' => false,
            'locked' => false,
            'canceled' => false,
        ]);

        /** @var Bus $bus */
        $bus = $depart->buses()->create([
            'name' => 'Bus Web Test',
            'nombre_place' => 50,
            'ticket_price' => 4000,
            'gp_ticket_price' => 6000,
            'closed' => false,
        ]);
        $bus->seats()->createMany(
            collect(range(1, 10))->map(fn (int $position) => [
                'booked' => false,
                'price' => 4000,
                'position_in_bus' => $position,
            ])->all()
        );

        $pointDep = PointDep::create([
            'name' => 'Gare routière '.uniqid(),
            'trajet_id' => $trajet->id,
            'disabled' => false,
            'visibilite' => Depart::VISIBILITE_ALL_CUSTOMERS,
        ]);
        $destination = Destination::create(['name' => 'Dakar centre', 'trajet_id' => $trajet->id]);

        $uuid = (string) Str::uuid();
        $groupId = (int) BookingManager::generateBookingGroupId();

        $bookings = collect([
            'Awa Diop' => '77'.random_int(1000000, 9999999),
            'Modou Fall' => '78'.random_int(1000000, 9999999),
        ])
            ->map(function (string $phone, string $name) use ($depart, $bus, $pointDep, $destination, $uuid, $groupId, $paid) {
                [$firstName, $lastName] = [explode(' ', $name)[0], explode(' ', $name)[1]];
                $customer = Customer::create([
                    'prenom' => $firstName,
                    'nom' => $lastName,
                    'phone_number' => $phone,
                    'last_active' => now(),
                ]);

                $ticketId = null;
                if ($paid) {
                    $ticket = (new Ticket)->forceFill([
                        'number' => random_int(1000000, 9999999),
                        'price' => 4000,
                        'soldBy' => 'test',
                        'soldAt' => now(),
                        'used' => false,
                        'canceled' => false,
                    ]);
                    $ticket->save();
                    $ticketId = $ticket->id;
                }

                return Booking::create([
                    'customer_id' => $customer->id,
                    'depart_id' => $depart->id,
                    'bus_id' => $bus->id,
                    'point_dep_id' => $pointDep->id,
                    'destination_id' => $destination->id,
                    'paye' => $paid,
                    'ticket_id' => $ticketId,
                    'group_id' => $groupId,
                    'uuid' => $uuid,
                    'booked_with_platform' => 'website',
                ]);
            });

        return ['uuid' => $uuid, 'groupId' => $groupId, 'bookings' => $bookings, 'depart' => $depart];
    }

    public function test_an_unknown_uuid_returns_404(): void
    {
        $this->get($this->websiteUrl('/reservations/'.Str::uuid()))->assertNotFound();
    }

    public function test_an_unpaid_group_shows_the_payment_buttons_and_the_non_refundable_reminder(): void
    {
        ['uuid' => $uuid] = $this->createBookingGroup(paid: false);

        $this->get($this->websiteUrl('/reservations/'.$uuid))
            ->assertStatus(200)
            ->assertSee('En attente de paiement')
            ->assertSee('Payer par Wave')
            ->assertSee('Payer par Orange Money')
            ->assertSee("n'est pas remboursable")
            ->assertSee('Awa Diop')
            ->assertDontSee('Télécharger mon ticket');
    }

    public function test_a_paid_group_shows_the_ticket_download_link(): void
    {
        ['uuid' => $uuid, 'groupId' => $groupId] = $this->createBookingGroup(paid: true);

        $this->get($this->websiteUrl('/reservations/'.$uuid))
            ->assertStatus(200)
            ->assertSee('Réservation payée')
            ->assertSee('Télécharger mon ticket')
            ->assertSee(route('tickets.group.show', $groupId), false)
            ->assertDontSee('Payer par Wave');
    }

    public function test_paying_the_group_by_wave_redirects_to_the_wave_checkout(): void
    {
        Http::fake([
            'api.wave.com/*' => Http::response([
                'id' => 'cos-grp1',
                'wave_launch_url' => 'https://pay.wave.com/c/cos-grp1?a=8000',
            ], 200),
        ]);

        ['uuid' => $uuid] = $this->createBookingGroup(paid: false);

        Livewire::test(BookingGroupShow::class, ['uuid' => $uuid])
            ->call('payWithWave')
            ->assertRedirect('https://pay.wave.com/c/cos-grp1?a=8000');
    }

    public function test_paying_by_orange_money_requires_the_paying_number(): void
    {
        ['uuid' => $uuid] = $this->createBookingGroup(paid: false);

        Livewire::test(BookingGroupShow::class, ['uuid' => $uuid])
            ->call('payWithOrangeMoney')
            ->assertHasErrors('orangeMoneyNumber');
    }

    public function test_a_passed_departure_cannot_be_paid(): void
    {
        ['uuid' => $uuid] = $this->createBookingGroup(paid: false, departPassed: true);

        $this->get($this->websiteUrl('/reservations/'.$uuid))
            ->assertStatus(200)
            ->assertSee('Départ passé')
            ->assertDontSee('Payer par Wave');
    }
}
