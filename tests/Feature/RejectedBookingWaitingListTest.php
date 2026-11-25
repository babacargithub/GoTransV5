<?php

namespace Tests\Feature;

use App\Livewire\Website\StudentBooking;
use App\Models\Depart;
use App\Models\Seat;
use App\Models\Trajet;
use App\Models\WaitingCustomer;
use App\Services\WaitingCustomerService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class RejectedBookingWaitingListTest extends TestCase
{
    use DatabaseTransactions;

    private function createDepartWithOneBus(bool $isBusClosed, bool $busHasFreeSeat = true): Depart
    {
        $trajet = Trajet::query()->has('pointDeps')->has('destinations')->firstOrFail();

        $depart = Depart::create([
            'name' => 'DEPART REJET '.uniqid(),
            'date' => now()->addDay()->format('Y-m-d H:i:s'),
            'trajet_id' => $trajet->id,
            'closed' => false,
            'locked' => false,
            'canceled' => false,
        ]);

        $bus = $depart->buses()->create([
            'name' => 'Bus Rejet',
            'nombre_place' => 57,
            'ticket_price' => 8000,
            'gp_ticket_price' => 6000,
            'closed' => $isBusClosed,
        ]);

        if ($busHasFreeSeat) {
            $bus->seats()->create([
                'seat_id' => Seat::query()->firstOrFail()->id,
                'booked' => false,
                'price' => 8000,
            ]);
        }

        return $depart->fresh();
    }

    /**
     * Fills the public website form like a student would and presses the final "Valider".
     */
    private function submitStudentBookingForm(Depart $depart, string $phoneNumber): Testable
    {
        $bookingForm = Livewire::test(StudentBooking::class, ['depart' => $depart]);
        $firstPointDepartId = $bookingForm->instance()->pointDepartOptions[0]['id'];

        return $bookingForm
            ->set('passengersCount', 1)
            ->set('passengers.0.full_name', 'Client Rejete')
            ->set('passengers.0.phone_number', $phoneNumber)
            ->set('passengers.0.point_dep_id', $firstPointDepartId)
            ->set('paymentMethod', 'wave')
            ->call('confirmBooking');
    }

    public function test_a_student_rejected_because_the_bus_is_closed_is_recorded_on_the_waiting_list(): void
    {
        $depart = $this->createDepartWithOneBus(isBusClosed: true);
        $phoneNumber = '77'.random_int(1000000, 9999999);

        $this->submitStudentBookingForm($depart, $phoneNumber)->assertSet('formError', "Désolé, le bus est plein, il n'y a plus de place !");

        $waitingCustomer = WaitingCustomer::query()->where('depart_id', $depart->id)->sole();
        $this->assertSame($phoneNumber, (string) $waitingCustomer->customer->phone_number);
        $this->assertSame($depart->buses->first()->id, $waitingCustomer->bus_id);
        $this->assertSame(WaitingCustomerService::REASON_BUS_CLOSED, $waitingCustomer->data['reason']);
    }

    public function test_a_student_rejected_because_the_bus_is_full_is_recorded_on_the_waiting_list(): void
    {
        $depart = $this->createDepartWithOneBus(isBusClosed: false, busHasFreeSeat: false);
        $phoneNumber = '77'.random_int(1000000, 9999999);

        $this->submitStudentBookingForm($depart, $phoneNumber)->assertSet('formError', "Désolé, le bus est plein, il n'y a plus de place !");

        $waitingCustomer = WaitingCustomer::query()->where('depart_id', $depart->id)->sole();
        $this->assertSame($phoneNumber, (string) $waitingCustomer->customer->phone_number);
        $this->assertSame(WaitingCustomerService::REASON_BUS_FULL, $waitingCustomer->data['reason']);
    }

    public function test_a_student_whose_booking_is_accepted_is_not_recorded_on_the_waiting_list(): void
    {
        Http::fake();
        $depart = $this->createDepartWithOneBus(isBusClosed: false);

        $this->submitStudentBookingForm($depart, '77'.random_int(1000000, 9999999));

        $this->assertSame(0, WaitingCustomer::query()->where('depart_id', $depart->id)->count());
    }
}
