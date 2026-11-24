<?php

namespace Tests\Feature\BackOffice;

use App\Enums\FeedbackCallOutcome;
use App\Livewire\BackOffice\DepartFeedbackCallQueue;
use App\Livewire\BackOffice\DepartFeedbackReport;
use App\Livewire\BackOffice\DepartList;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Depart;
use App\Models\DepartFeedback;
use App\Models\FeedbackCriterion;
use App\Models\Seat;
use App\Models\Ticket;
use App\Models\Trajet;
use App\Models\User;
use App\Services\DepartFeedbackService;
use Database\Seeders\FeedbackCriterionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class DepartFeedbackTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FeedbackCriterionSeeder::class);
    }

    private function createDepartWithPaidBookings(int $paidBookingsCount): Depart
    {
        $trajet = Trajet::query()->has('pointDeps')->has('destinations')->firstOrFail();

        $depart = Depart::create([
            'name' => 'DEPART FEEDBACK '.uniqid(),
            'date' => now()->subDay()->format('Y-m-d H:i:s'),
            'trajet_id' => $trajet->id,
            'closed' => false,
            'locked' => false,
            'canceled' => false,
        ]);

        $bus = $depart->buses()->create([
            'name' => 'Bus Feedback',
            'nombre_place' => 57,
            'ticket_price' => 8000,
            'gp_ticket_price' => 6000,
            'closed' => false,
        ]);

        for ($bookingIndex = 0; $bookingIndex < $paidBookingsCount; $bookingIndex++) {
            $this->createPaidBooking($depart, $bus, $trajet);
        }

        return $depart->fresh();
    }

    private function createPaidBooking(Depart $depart, $bus, Trajet $trajet, ?int $groupId = null, bool $isMainBooking = false): Booking
    {
        $ticket = new Ticket;
        $ticket->forceFill([
            'number' => random_int(1_000_000, 9_999_999_999),
            'price' => 8000,
            'payment_method' => 'wave',
            'used' => true,
            'soldBy' => 'agence test',
            'soldAt' => now(),
            'expiryDate' => now()->addDays(30),
        ])->save();

        return $bus->bookings()->create([
            'customer_id' => Customer::create([
                'prenom' => 'awa',
                'nom' => 'ndiaye',
                'phone_number' => 770000000 + random_int(1, 9999999),
            ])->id,
            'depart_id' => $depart->id,
            'point_dep_id' => $trajet->pointDeps()->firstOrFail()->id,
            'destination_id' => $trajet->destinations()->firstOrFail()->id,
            'seat_id' => $bus->seats()->create([
                'seat_id' => Seat::query()->firstOrFail()->id,
                'booked' => true,
                'price' => 8000,
            ])->id,
            'ticket_id' => $ticket->id,
            'paye' => true,
            'group_id' => $groupId,
            'is_main_booking' => $isMainBooking,
        ]);
    }

    private function createArrivedDepart(int $paidBookingsCount): Depart
    {
        $depart = $this->createDepartWithPaidBookings($paidBookingsCount);
        app(DepartFeedbackService::class)->markDepartArrived($depart, User::factory()->create());

        return $depart->fresh();
    }

    private function agent(): User
    {
        return $this->createUserWithPermissions(['conduct-depart-feedback-calls']);
    }

    public function test_marking_a_depart_arrived_creates_one_call_per_customer_and_is_idempotent(): void
    {
        $depart = $this->createDepartWithPaidBookings(2);
        $bus = $depart->buses()->firstOrFail();
        $trajet = $depart->trajet;
        $this->createPaidBooking($depart, $bus, $trajet, 987654, false);
        $this->createPaidBooking($depart, $bus, $trajet, 987654, true);
        $markedBy = User::factory()->create();

        $createdCallsCount = app(DepartFeedbackService::class)->markDepartArrived($depart, $markedBy);
        $secondRunCreatedCallsCount = app(DepartFeedbackService::class)->markDepartArrived($depart, $markedBy);

        $this->assertSame(3, $createdCallsCount, 'two singles + one call for the whole group');
        $this->assertSame(0, $secondRunCreatedCallsCount);
        $this->assertSame(3, DepartFeedback::query()->where('depart_id', $depart->id)->count());
        $this->assertNotNull($depart->fresh()->arrived_at);
        $this->assertTrue(
            Booking::query()->find(DepartFeedback::query()->where('depart_id', $depart->id)->pluck('booking_id'))
                ->where('group_id', 987654)->every(fn (Booking $booking) => $booking->is_main_booking)
        );
    }

    public function test_the_depart_list_marks_a_depart_arrived_only_for_users_allowed_to(): void
    {
        $depart = $this->createDepartWithPaidBookings(1);

        Livewire::actingAs($this->createUserWithPermissions([]))
            ->test(DepartList::class)
            ->call('askToMarkDepartArrived', $depart->id)
            ->call('confirmMarkDepartArrived');

        $this->assertNull($depart->fresh()->arrived_at);

        Livewire::actingAs($this->createUserWithPermissions(['mark-depart-arrived']))
            ->test(DepartList::class)
            ->call('askToMarkDepartArrived', $depart->id)
            ->call('confirmMarkDepartArrived');

        $this->assertNotNull($depart->fresh()->arrived_at);
        $this->assertSame(1, $depart->feedbacks()->count());
    }

    public function test_an_arrived_depart_links_to_the_calls_and_the_report_only_for_permitted_users(): void
    {
        $depart = $this->createDepartWithPaidBookings(1);
        app(DepartFeedbackService::class)->markDepartArrived($depart, User::factory()->create());
        $callsUrl = route('back-office.depart-feedbacks.calls', ['depart' => $depart->id]);
        $reportUrl = route('back-office.depart-feedbacks.report', ['depart' => $depart->id]);

        Livewire::actingAs($this->createUserWithFullAccess())
            ->test(DepartList::class)
            ->set('showPastDeparts', true)
            ->set('trajetFilter', (string) $depart->trajet_id)
            ->assertSee($callsUrl, false)
            ->assertSee($reportUrl, false);

        Livewire::actingAs($this->createUserWithPermissions([]))
            ->test(DepartList::class)
            ->set('showPastDeparts', true)
            ->set('trajetFilter', (string) $depart->trajet_id)
            ->assertDontSee($callsUrl, false)
            ->assertDontSee($reportUrl, false);
    }

    public function test_the_call_queue_requires_the_conduct_calls_permission(): void
    {
        $this->actingAs($this->createUserWithPermissions([]))
            ->get(route('back-office.depart-feedbacks.calls'))
            ->assertForbidden();
    }

    public function test_an_agent_rates_a_customer_and_the_feedback_is_saved_without_recording(): void
    {
        $depart = $this->createArrivedDepart(2);
        $agent = $this->agent();

        $component = Livewire::actingAs($agent)
            ->test(DepartFeedbackCallQueue::class)
            ->set('selectedDepartId', $depart->id)
            ->assertSee('data-testid="current-customer-name"', false);

        $openedFeedbackId = $component->get('currentFeedbackId');
        $this->assertNotNull($openedFeedbackId);

        $component->call('startCall')->call('chooseOutcome', 'completed');

        foreach (FeedbackCriterion::query()->active()->get() as $criterion) {
            $component->call('setScore', $criterion->id, 16);
        }

        $component->set('comment', 'Très bon voyage')->call('saveAndNext');

        $savedFeedback = DepartFeedback::query()->findOrFail($openedFeedbackId);
        $this->assertSame(FeedbackCallOutcome::Completed, $savedFeedback->outcome);
        $this->assertNotNull($savedFeedback->completed_at);
        $this->assertNotNull($savedFeedback->called_at);
        $this->assertNull($savedFeedback->recording_path);
        $this->assertSame('Très bon voyage', $savedFeedback->comment);
        $this->assertSame(FeedbackCriterion::query()->active()->count(), $savedFeedback->ratings()->count());
        $this->assertSame(16.0, $savedFeedback->averageScore());
        $this->assertNotSame($openedFeedbackId, $component->get('currentFeedbackId'), 'moves on to the next customer');
    }

    public function test_a_grade_outside_one_to_twenty_is_refused(): void
    {
        $depart = $this->createArrivedDepart(1);
        $criterionId = FeedbackCriterion::query()->active()->firstOrFail()->id;

        $component = Livewire::actingAs($this->agent())
            ->test(DepartFeedbackCallQueue::class)
            ->set('selectedDepartId', $depart->id)
            ->call('chooseOutcome', 'completed')
            ->call('setScore', $criterionId, 21)
            ->call('setScore', $criterionId, 0);

        $this->assertSame(0, $depart->feedbacks()->firstOrFail()->ratings()->count());

        $component->call('setScore', $criterionId, 20);
        $this->assertSame(1, $depart->feedbacks()->firstOrFail()->ratings()->count());
    }

    public function test_au_suivant_skips_to_another_customer_and_keeps_the_skipped_one_in_the_queue(): void
    {
        $depart = $this->createArrivedDepart(2);
        $agent = $this->agent();

        $component = Livewire::actingAs($agent)
            ->test(DepartFeedbackCallQueue::class)
            ->set('selectedDepartId', $depart->id);

        $firstFeedbackId = $component->get('currentFeedbackId');
        $component->call('skipToNextCard');
        $secondFeedbackId = $component->get('currentFeedbackId');

        $this->assertNotSame($firstFeedbackId, $secondFeedbackId);
        $this->assertNull(DepartFeedback::query()->findOrFail($firstFeedbackId)->claimed_by_user_id);

        $component->call('skipToNextCard');
        $this->assertSame($firstFeedbackId, $component->get('currentFeedbackId'), 'wraps around to the skipped customer');
    }

    public function test_au_suivant_keeps_the_current_customer_when_nobody_else_is_left(): void
    {
        $depart = $this->createArrivedDepart(1);

        $component = Livewire::actingAs($this->agent())
            ->test(DepartFeedbackCallQueue::class)
            ->set('selectedDepartId', $depart->id);
        $onlyFeedbackId = $component->get('currentFeedbackId');

        $component->call('skipToNextCard')
            ->assertSet('currentFeedbackId', $onlyFeedbackId)
            ->assertSet('flashErrorMessage', 'Il n\'y a pas d\'autre client à appeler pour le moment.');
    }

    public function test_saving_is_refused_until_every_criterion_is_rated(): void
    {
        $depart = $this->createArrivedDepart(1);

        $component = Livewire::actingAs($this->agent())
            ->test(DepartFeedbackCallQueue::class)
            ->set('selectedDepartId', $depart->id)
            ->call('chooseOutcome', 'completed')
            ->call('setScore', FeedbackCriterion::query()->active()->firstOrFail()->id, 16)
            ->call('saveAndNext');

        $component->assertSet('flashErrorMessage', 'Notez tous les critères avant d\'enregistrer.');
        $this->assertNull($depart->feedbacks()->firstOrFail()->completed_at);
    }

    public function test_an_unanswered_customer_goes_to_the_call_back_list_and_a_refusal_is_final(): void
    {
        $depart = $this->createArrivedDepart(2);

        $component = Livewire::actingAs($this->agent())
            ->test(DepartFeedbackCallQueue::class)
            ->set('selectedDepartId', $depart->id);

        $noAnswerFeedbackId = $component->get('currentFeedbackId');
        $component->call('chooseOutcome', 'no_answer');
        $refusedFeedbackId = $component->get('currentFeedbackId');
        $component->call('chooseOutcome', 'refused');

        $noAnswerFeedback = DepartFeedback::query()->findOrFail($noAnswerFeedbackId);
        $this->assertSame(FeedbackCallOutcome::NoAnswer, $noAnswerFeedback->outcome);
        $this->assertNull($noAnswerFeedback->completed_at);
        $this->assertNull($noAnswerFeedback->claimed_by_user_id);
        $this->assertNotNull(DepartFeedback::query()->findOrFail($refusedFeedbackId)->completed_at);
        $this->assertSame(1, $component->instance()->tabCounts['toCallBack']);
    }

    public function test_two_agents_never_get_the_same_customer(): void
    {
        $depart = $this->createArrivedDepart(2);
        $service = app(DepartFeedbackService::class);

        $firstCard = $service->claimNextCard($depart, $this->agent());
        $secondCard = $service->claimNextCard($depart, $this->agent());
        $thirdCard = $service->claimNextCard($depart, $this->agent());

        $this->assertNotSame($firstCard->id, $secondCard->id);
        $this->assertNull($thirdCard);
    }

    public function test_an_agent_cannot_write_on_a_card_held_by_another_agent(): void
    {
        $depart = $this->createArrivedDepart(1);
        $service = app(DepartFeedbackService::class);
        $card = $service->claimNextCard($depart, $this->agent());

        $this->expectException(\InvalidArgumentException::class);
        $service->recordOutcome($card, $this->agent(), FeedbackCallOutcome::Refused);
    }

    public function test_the_report_shows_averages_and_recordings_stream_only_to_allowed_listeners(): void
    {
        $depart = $this->createArrivedDepart(1);
        $feedback = $depart->feedbacks()->firstOrFail();
        $criterion = FeedbackCriterion::query()->active()->firstOrFail();
        $feedback->forceFill(['outcome' => FeedbackCallOutcome::Completed, 'completed_at' => now(), 'recording_path' => 'depart-feedback-recordings/test-missing.mp3'])->save();
        $feedback->ratings()->create(['feedback_criterion_id' => $criterion->id, 'score' => 16]);

        Livewire::actingAs($this->createUserWithPermissions(['view-depart-feedback']))
            ->test(DepartFeedbackReport::class)
            ->set('selectedDepartId', $depart->id)
            ->assertSee($criterion->label)
            ->assertSee('16,0 / 20')
            ->set('conclusion', 'Améliorer la ponctualité')
            ->call('saveConclusion');

        $this->assertSame('Améliorer la ponctualité', $depart->fresh()->feedback_conclusion);

        $this->actingAs($this->createUserWithPermissions(['view-depart-feedback']))
            ->get(route('back-office.depart-feedbacks.recording', $feedback))
            ->assertForbidden();

        $this->actingAs($this->createUserWithPermissions(['listen-depart-feedback-recordings']))
            ->get(route('back-office.depart-feedbacks.recording', $feedback))
            ->assertNotFound();
    }
}
