<?php

namespace Tests\Feature;

use App\Enums\AmbassadorEarningStatus;
use App\Enums\AmbassadorEarningType;
use App\Http\Controllers\BookingController;
use App\Jobs\RecordTicketPaymentInCaisse;
use App\Manager\BookingManager;
use App\Models\Ambassador;
use App\Models\AmbassadorEarning;
use App\Models\AmbassadorPayout;
use App\Models\AmbassadorRewardRule;
use App\Models\Booking;
use App\Models\Bus;
use App\Models\Customer;
use App\Models\Depart;
use App\Models\Destination;
use App\Models\PointDep;
use App\Models\Ticket;
use App\Models\Trajet;
use App\Services\AmbassadorEarningService;
use App\Services\AmbassadorPerformanceService;
use App\Services\NotificationService;
use App\Utils\NotificationSender\SMSSender\SMSSender;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * The ambassador earning ledger is driven by booking events (ticket paid, cancelled, refunded,
 * transferred). These tests prove each event reaches the lifecycle service exactly once, and that a
 * failure of the ledger can never break the customer-facing operation.
 */
class AmbassadorEarningWiringTest extends TestCase
{
    use DatabaseTransactions;

    private const FIRST_DEPART_DATE = '2030-03-10 08:00:00';

    private const SECOND_DEPART_DATE = '2030-03-20 09:30:00';

    private function createDepartWithBus(string $date, int $seatCount = 10): Depart
    {
        $trajet = Trajet::create(['name' => 'Caravane '.uniqid(), 'departure_city' => 'DAKAR', 'arrival_city' => 'SAINT-LOUIS']);
        $depart = Depart::create([
            'name' => 'DEPART WIRING '.uniqid(),
            'date' => $date,
            'trajet_id' => $trajet->id,
            'visibilite' => Depart::VISIBILITE_ALL_CUSTOMERS,
            'closed' => false,
            'locked' => false,
            'canceled' => false,
        ]);
        $bus = $depart->buses()->create([
            'name' => 'Bus Wiring Test',
            'nombre_place' => 50,
            'ticket_price' => 4000,
            'gp_ticket_price' => 6000,
            'closed' => false,
        ]);
        if ($seatCount > 0) {
            $bus->seats()->createMany(
                collect(range(1, $seatCount))->map(fn (int $position) => [
                    'booked' => false,
                    'price' => 4000,
                    'position_in_bus' => $position,
                ])->all()
            );
        }
        PointDep::create(['name' => 'Gare '.uniqid(), 'trajet_id' => $trajet->id, 'disabled' => false]);
        Destination::create(['name' => 'Dakar centre', 'trajet_id' => $trajet->id]);

        return $depart;
    }

    private function createCustomer(?Ambassador $ambassador = null): Customer
    {
        return Customer::create([
            'prenom' => 'Awa',
            'nom' => 'Diop',
            'phone_number' => fake()->unique()->numerify('77#######'),
            'ambassador_id' => $ambassador?->id,
            'last_active' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createUnpaidBooking(Customer $customer, Depart $depart, array $attributes = []): Booking
    {
        return Booking::create(array_merge([
            'customer_id' => $customer->id,
            'depart_id' => $depart->id,
            'bus_id' => $depart->buses()->firstOrFail()->id,
            'point_dep_id' => PointDep::where('trajet_id', $depart->trajet_id)->firstOrFail()->id,
            'destination_id' => Destination::where('trajet_id', $depart->trajet_id)->firstOrFail()->id,
            'paye' => false,
            'group_id' => (int) BookingManager::generateBookingGroupId(),
        ], $attributes));
    }

    private function createWavePaidBooking(Customer $customer, Depart $depart, string $checkoutId): Booking
    {
        $ticket = new Ticket;
        $ticket->forceFill([
            'number' => random_int(1_000_000, 9_999_999_999),
            'price' => 4000,
            'payment_method' => 'wave',
            'comment' => $checkoutId,
            'used' => false,
            'soldBy' => 'system',
            'soldAt' => now(),
            'expiryDate' => now()->addDays(30),
        ])->save();

        return $this->createUnpaidBooking($customer, $depart, ['ticket_id' => $ticket->id, 'paye' => true]);
    }

    private function earningsOf(Ambassador $ambassador): Collection
    {
        return AmbassadorEarning::where('ambassador_id', $ambassador->id)->get();
    }

    private function ignoreCustomerNotifications(): void
    {
        $this->mock(NotificationService::class)->shouldIgnoreMissing();
    }

    private function makeLifecycleServiceFail(): void
    {
        $this->mock(AmbassadorEarningService::class)
            ->shouldReceive('recordAcquisitionEarning', 'cancelEarningsOfBooking', 'rescheduleEarningsOfTransferredBooking')
            ->andThrow(new RuntimeException('ledger is down'));
    }

    // ---------------------------------------------------------------- ticket assigned (BookingManager)

    public function test_assigning_a_ticket_to_a_referred_customers_booking_creates_the_pending_earning(): void
    {
        Queue::fake([RecordTicketPaymentInCaisse::class]);
        $ambassador = Ambassador::factory()->create();
        $booking = $this->createUnpaidBooking($this->createCustomer($ambassador), $this->createDepartWithBus(self::FIRST_DEPART_DATE));

        app(BookingManager::class)->assignTicketToBooking($booking, 'wave');

        $earning = $this->earningsOf($ambassador)->sole();
        $this->assertSame(AmbassadorEarningStatus::Pending, $earning->status);
        $this->assertSame(AmbassadorEarningType::Acquisition, $earning->type);
        $this->assertSame($booking->id, $earning->booking_id);
        $this->assertSame('2030-03-11 08:00:00', $earning->approvable_at->format('Y-m-d H:i:s'));
        $this->assertNotNull($booking->fresh()->ticket_id);
    }

    public function test_assigning_a_ticket_to_a_customer_without_ambassador_creates_no_earning(): void
    {
        Queue::fake([RecordTicketPaymentInCaisse::class]);
        $earningsCountBefore = AmbassadorEarning::count();
        $booking = $this->createUnpaidBooking($this->createCustomer(), $this->createDepartWithBus(self::FIRST_DEPART_DATE));

        app(BookingManager::class)->assignTicketToBooking($booking, 'wave');

        $this->assertSame($earningsCountBefore, AmbassadorEarning::count());
        $this->assertNotNull($booking->fresh()->ticket_id);
    }

    public function test_a_failing_ledger_never_prevents_the_ticket_from_being_assigned_and_the_failure_is_reported(): void
    {
        Queue::fake([RecordTicketPaymentInCaisse::class]);
        Exceptions::fake();
        $this->makeLifecycleServiceFail();
        $booking = $this->createUnpaidBooking($this->createCustomer(Ambassador::factory()->create()), $this->createDepartWithBus(self::FIRST_DEPART_DATE));

        app(BookingManager::class)->assignTicketToBooking($booking, 'wave');

        $this->assertNotNull($booking->fresh()->ticket_id);
        Queue::assertPushed(RecordTicketPaymentInCaisse::class);
        Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'ledger is down');
    }

    public function test_the_earning_is_rolled_back_with_the_sale_when_the_enclosing_transaction_fails(): void
    {
        Queue::fake([RecordTicketPaymentInCaisse::class]);
        $ambassador = Ambassador::factory()->create();
        $booking = $this->createUnpaidBooking($this->createCustomer($ambassador), $this->createDepartWithBus(self::FIRST_DEPART_DATE));

        try {
            DB::transaction(function () use ($booking): void {
                app(BookingManager::class)->assignTicketToBooking($booking, 'wave');

                throw new RuntimeException('no seat left');
            });
        } catch (RuntimeException) {
            // expected: the sale failed after the ticket was assigned
        }

        $this->assertSame(0, $this->earningsOf($ambassador)->count());
        $this->assertNull($booking->fresh()->ticket_id);
    }

    public function test_an_online_payment_creates_the_earning_exactly_once_even_when_the_callback_is_replayed(): void
    {
        Queue::fake([RecordTicketPaymentInCaisse::class]);
        $this->ignoreCustomerNotifications();
        $ambassador = Ambassador::factory()->create();
        $booking = $this->createUnpaidBooking($this->createCustomer($ambassador), $this->createDepartWithBus(self::FIRST_DEPART_DATE));
        $logger = app(LoggerInterface::class);

        app(BookingManager::class)->saveTicketPayementForOnlineUsers($booking, $logger, 'wave');
        app(BookingManager::class)->saveTicketPayementForOnlineUsers($booking->fresh(), $logger, 'wave');

        $this->assertSame(1, $this->earningsOf($ambassador)->count());
    }

    public function test_a_group_payment_creates_one_earning_per_new_customer_and_a_replayed_callback_adds_none(): void
    {
        Queue::fake([RecordTicketPaymentInCaisse::class]);
        $this->ignoreCustomerNotifications();
        $ambassador = Ambassador::factory()->create();
        $depart = $this->createDepartWithBus(self::FIRST_DEPART_DATE);
        $bookings = collect([
            $this->createUnpaidBooking($this->createCustomer($ambassador), $depart),
            $this->createUnpaidBooking($this->createCustomer($ambassador), $depart),
            $this->createUnpaidBooking($this->createCustomer(), $depart),
        ]);
        $logger = app(LoggerInterface::class);

        app(BookingManager::class)->saveTicketPaymentMultipleBooking($depart, $depart->buses()->first(), $bookings, $logger, 'wave', []);
        app(BookingManager::class)->saveTicketPaymentMultipleBooking($depart, $depart->buses()->first(), $bookings->map->fresh(), $logger, 'wave', []);

        $this->assertSame(2, $this->earningsOf($ambassador)->count());
        $this->assertEqualsCanonicalizing(
            $bookings->take(2)->pluck('id')->all(),
            $this->earningsOf($ambassador)->pluck('booking_id')->all(),
        );
    }

    // ---------------------------------------------------------------- back-office / agent payment

    public function test_collecting_a_cash_payment_through_the_api_creates_the_earning(): void
    {
        Queue::fake([RecordTicketPaymentInCaisse::class]);
        $this->ignoreCustomerNotifications();
        Sanctum::actingAs($this->createUserWithFullAccess());
        $ambassador = Ambassador::factory()->create();
        $booking = $this->createUnpaidBooking($this->createCustomer($ambassador), $this->createDepartWithBus(self::FIRST_DEPART_DATE));

        $this->putJson("/api/bookings/{$booking->id}/save_ticket_payment", ['payment_method' => 'cash'])->assertOk();

        $this->assertSame($booking->id, $this->earningsOf($ambassador)->sole()->booking_id);
    }

    public function test_a_payment_that_fails_for_lack_of_seat_creates_no_earning(): void
    {
        Queue::fake([RecordTicketPaymentInCaisse::class]);
        $this->ignoreCustomerNotifications();
        Sanctum::actingAs($this->createUserWithFullAccess());
        $ambassador = Ambassador::factory()->create();
        $booking = $this->createUnpaidBooking($this->createCustomer($ambassador), $this->createDepartWithBus(self::FIRST_DEPART_DATE, seatCount: 0));

        $this->putJson("/api/bookings/{$booking->id}/save_ticket_payment", ['payment_method' => 'cash'])->assertUnprocessable();

        $this->assertSame(0, $this->earningsOf($ambassador)->count());
        $this->assertFalse($booking->fresh()->hasTicket());
    }

    public function test_a_failing_ledger_never_blocks_a_cash_payment(): void
    {
        Queue::fake([RecordTicketPaymentInCaisse::class]);
        Exceptions::fake();
        $this->ignoreCustomerNotifications();
        $this->makeLifecycleServiceFail();
        Sanctum::actingAs($this->createUserWithFullAccess());
        $booking = $this->createUnpaidBooking($this->createCustomer(Ambassador::factory()->create()), $this->createDepartWithBus(self::FIRST_DEPART_DATE));

        $this->putJson("/api/bookings/{$booking->id}/save_ticket_payment", ['payment_method' => 'cash'])->assertOk();

        $this->assertTrue($booking->fresh()->hasTicket());
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_creating_an_already_paid_booking_creates_the_earning(): void
    {
        $this->ignoreCustomerNotifications();
        $ambassador = Ambassador::factory()->create();
        $depart = $this->createDepartWithBus(self::FIRST_DEPART_DATE);
        $customer = $this->createCustomer($ambassador);
        $request = Request::create('/', 'POST', [
            'customer_id' => $customer->id,
            'point_dep_id' => PointDep::where('trajet_id', $depart->trajet_id)->firstOrFail()->id,
            'destination_id' => Destination::where('trajet_id', $depart->trajet_id)->firstOrFail()->id,
            'ticket_paid' => true,
        ]);

        $response = app(BookingController::class)->store($depart, $request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            Booking::where('customer_id', $customer->id)->firstOrFail()->id,
            $this->earningsOf($ambassador)->sole()->booking_id,
        );
    }

    public function test_creating_an_unpaid_booking_creates_no_earning(): void
    {
        $ambassador = Ambassador::factory()->create();
        $depart = $this->createDepartWithBus(self::FIRST_DEPART_DATE);
        $request = Request::create('/', 'POST', [
            'customer_id' => $this->createCustomer($ambassador)->id,
            'point_dep_id' => PointDep::where('trajet_id', $depart->trajet_id)->firstOrFail()->id,
            'destination_id' => Destination::where('trajet_id', $depart->trajet_id)->firstOrFail()->id,
            'ticket_paid' => false,
        ]);

        app(BookingController::class)->store($depart, $request);

        $this->assertSame(0, $this->earningsOf($ambassador)->count());
    }

    // ---------------------------------------------------------------- cancellation

    public function test_cancelling_a_paid_booking_cancels_its_pending_earning(): void
    {
        Sanctum::actingAs($this->createUserWithFullAccess());
        $ambassador = Ambassador::factory()->create();
        $booking = $this->createWavePaidBooking($this->createCustomer($ambassador), $this->createDepartWithBus(self::FIRST_DEPART_DATE), 'cos-cancel-1');
        app(AmbassadorEarningService::class)->recordAcquisitionEarning($booking);

        $this->deleteJson("/api/bookings/{$booking->id}")->assertNoContent();

        $this->assertSoftDeleted($booking);
        $this->assertSame(AmbassadorEarningStatus::Cancelled, $this->earningsOf($ambassador)->sole()->status);
    }

    public function test_cancelling_a_booking_after_its_earning_was_paid_out_keeps_the_paid_earning(): void
    {
        Sanctum::actingAs($this->createUserWithFullAccess());
        $ambassador = Ambassador::factory()->create();
        $booking = $this->createWavePaidBooking($this->createCustomer($ambassador), $this->createDepartWithBus(self::FIRST_DEPART_DATE), 'cos-cancel-2');
        $payout = AmbassadorPayout::factory()->create(['ambassador_id' => $ambassador->id]);
        AmbassadorEarning::factory()->create([
            'ambassador_id' => $ambassador->id,
            'booking_id' => $booking->id,
            'status' => AmbassadorEarningStatus::Paid,
            'paid_at' => now(),
            'ambassador_payout_id' => $payout->id,
        ]);

        $this->deleteJson("/api/bookings/{$booking->id}")->assertNoContent();

        $this->assertSame(AmbassadorEarningStatus::Paid, $this->earningsOf($ambassador)->sole()->status);
    }

    public function test_cancelling_an_unpaid_booking_succeeds_and_touches_no_earning(): void
    {
        Sanctum::actingAs($this->createUserWithFullAccess());
        $ambassador = Ambassador::factory()->create();
        $unrelatedEarning = AmbassadorEarning::factory()->create(['ambassador_id' => $ambassador->id]);
        $booking = $this->createUnpaidBooking($this->createCustomer($ambassador), $this->createDepartWithBus(self::FIRST_DEPART_DATE));

        $this->deleteJson("/api/bookings/{$booking->id}")->assertNoContent();

        $this->assertSoftDeleted($booking);
        $this->assertSame(AmbassadorEarningStatus::Pending, $unrelatedEarning->fresh()->status);
    }

    public function test_a_failing_ledger_never_blocks_a_cancellation(): void
    {
        Sanctum::actingAs($this->createUserWithFullAccess());
        Exceptions::fake();
        $this->makeLifecycleServiceFail();
        $booking = $this->createWavePaidBooking($this->createCustomer(Ambassador::factory()->create()), $this->createDepartWithBus(self::FIRST_DEPART_DATE), 'cos-cancel-3');

        $this->deleteJson("/api/bookings/{$booking->id}")->assertNoContent();

        $this->assertSoftDeleted($booking);
        Exceptions::assertReported(RuntimeException::class);
    }

    // ---------------------------------------------------------------- refund

    public function test_refunding_a_booking_paid_alone_cancels_its_earning(): void
    {
        Sanctum::actingAs($this->createUserWithFullAccess());
        Http::preventStrayRequests();
        Http::fake(['api.wave.com/v1/checkout/sessions/cos-refund-1/refund' => Http::response([], 200)]);
        $ambassador = Ambassador::factory()->create();
        $booking = $this->createWavePaidBooking($this->createCustomer($ambassador), $this->createDepartWithBus(self::FIRST_DEPART_DATE), 'cos-refund-1');
        app(AmbassadorEarningService::class)->recordAcquisitionEarning($booking);

        $this->postJson("/api/bookings/{$booking->id}/refund")->assertOk();

        $this->assertSoftDeleted($booking);
        $this->assertSame(AmbassadorEarningStatus::Cancelled, $this->earningsOf($ambassador)->sole()->status);
    }

    public function test_refunding_one_booking_of_a_group_cancels_only_that_bookings_earning(): void
    {
        Sanctum::actingAs($this->createUserWithFullAccess());
        Http::preventStrayRequests();
        Http::fake(['api.wave.com/v1/checkout/sessions/cos-group-refund' => Http::response(['id' => 'cos-group-refund', 'transaction_id' => 'TX-1'])]);
        $this->mock(SMSSender::class)->shouldReceive('sendSms')->andReturn(true);
        $ambassador = Ambassador::factory()->create();
        $depart = $this->createDepartWithBus(self::FIRST_DEPART_DATE);
        $groupId = (int) BookingManager::generateBookingGroupId();
        $refundedBooking = $this->createWavePaidBooking($this->createCustomer($ambassador), $depart, 'cos-group-refund');
        $refundedBooking->update(['group_id' => $groupId]);
        $keptBooking = $this->createWavePaidBooking($this->createCustomer($ambassador), $depart, 'cos-group-refund');
        $keptBooking->update(['group_id' => $groupId]);
        $lifecycleService = app(AmbassadorEarningService::class);
        $refundedEarning = $lifecycleService->recordAcquisitionEarning($refundedBooking);
        $keptEarning = $lifecycleService->recordAcquisitionEarning($keptBooking);

        $this->postJson("/api/bookings/{$refundedBooking->id}/refund")->assertOk();

        $this->assertSame(AmbassadorEarningStatus::Cancelled, $refundedEarning->fresh()->status);
        $this->assertSame(AmbassadorEarningStatus::Pending, $keptEarning->fresh()->status);
    }

    public function test_a_refused_refund_leaves_the_earning_untouched(): void
    {
        Sanctum::actingAs($this->createUserWithFullAccess());
        $ambassador = Ambassador::factory()->create();
        $booking = $this->createUnpaidBooking($this->createCustomer($ambassador), $this->createDepartWithBus(self::FIRST_DEPART_DATE));
        $earning = AmbassadorEarning::factory()->create(['ambassador_id' => $ambassador->id, 'booking_id' => $booking->id]);

        $this->postJson("/api/bookings/{$booking->id}/refund")->assertUnprocessable();

        $this->assertSame(AmbassadorEarningStatus::Pending, $earning->fresh()->status);
    }

    // ---------------------------------------------------------------- transfer

    private function transferTo(Booking $booking, Bus $targetBus): TestResponse
    {
        return $this->putJson("/api/bookings/{$booking->id}/transfer/target_bus/{$targetBus->id}");
    }

    public function test_transferring_a_booking_to_a_later_departure_postpones_its_pending_earning_without_cancelling_it(): void
    {
        Sanctum::actingAs($this->createUserWithFullAccess());
        $this->ignoreCustomerNotifications();
        $ambassador = Ambassador::factory()->create();
        $booking = $this->createWavePaidBooking($this->createCustomer($ambassador), $this->createDepartWithBus(self::FIRST_DEPART_DATE), 'cos-transfer-1');
        $earning = app(AmbassadorEarningService::class)->recordAcquisitionEarning($booking);
        $targetBus = $this->createDepartWithBus(self::SECOND_DEPART_DATE)->buses()->firstOrFail();

        $this->transferTo($booking, $targetBus)->assertOk();

        $this->assertSame($targetBus->depart_id, $booking->fresh()->depart_id);
        $this->assertSame(AmbassadorEarningStatus::Pending, $earning->fresh()->status);
        $this->assertSame('2030-03-21 09:30:00', $earning->fresh()->approvable_at->format('Y-m-d H:i:s'));
        $this->assertSame($booking->id, $earning->fresh()->booking_id);
        $this->assertSame(500, $earning->fresh()->amount);
    }

    public function test_transferring_a_booking_never_changes_an_earning_that_was_already_approved(): void
    {
        Sanctum::actingAs($this->createUserWithFullAccess());
        $this->ignoreCustomerNotifications();
        $ambassador = Ambassador::factory()->create();
        $booking = $this->createWavePaidBooking($this->createCustomer($ambassador), $this->createDepartWithBus(self::FIRST_DEPART_DATE), 'cos-transfer-2');
        $approvedEarning = AmbassadorEarning::factory()->approved()->create([
            'ambassador_id' => $ambassador->id,
            'booking_id' => $booking->id,
            'approvable_at' => '2030-03-11 08:00:00',
        ]);
        $targetBus = $this->createDepartWithBus(self::SECOND_DEPART_DATE)->buses()->firstOrFail();

        $this->transferTo($booking, $targetBus)->assertOk();

        $this->assertSame(AmbassadorEarningStatus::Approved, $approvedEarning->fresh()->status);
        $this->assertSame('2030-03-11 08:00:00', $approvedEarning->fresh()->approvable_at->format('Y-m-d H:i:s'));
    }

    public function test_a_rejected_transfer_leaves_the_earning_schedule_untouched(): void
    {
        Sanctum::actingAs($this->createUserWithFullAccess());
        $this->ignoreCustomerNotifications();
        $ambassador = Ambassador::factory()->create();
        $depart = $this->createDepartWithBus(self::FIRST_DEPART_DATE);
        $booking = $this->createWavePaidBooking($this->createCustomer($ambassador), $depart, 'cos-transfer-3');
        $earning = app(AmbassadorEarningService::class)->recordAcquisitionEarning($booking);

        $this->transferTo($booking, $depart->buses()->firstOrFail())->assertUnprocessable();

        $this->assertSame('2030-03-11 08:00:00', $earning->fresh()->approvable_at->format('Y-m-d H:i:s'));
    }

    public function test_a_transfer_to_a_full_bus_leaves_the_earning_schedule_untouched(): void
    {
        Sanctum::actingAs($this->createUserWithFullAccess());
        $this->ignoreCustomerNotifications();
        $ambassador = Ambassador::factory()->create();
        $booking = $this->createWavePaidBooking($this->createCustomer($ambassador), $this->createDepartWithBus(self::FIRST_DEPART_DATE), 'cos-transfer-4');
        $earning = app(AmbassadorEarningService::class)->recordAcquisitionEarning($booking);
        $busWithoutSeat = $this->createDepartWithBus(self::SECOND_DEPART_DATE, seatCount: 0)->buses()->firstOrFail();

        $this->transferTo($booking, $busWithoutSeat)->assertUnprocessable();

        $this->assertSame('2030-03-11 08:00:00', $earning->fresh()->approvable_at->format('Y-m-d H:i:s'));
    }

    public function test_a_failing_ledger_never_blocks_a_transfer(): void
    {
        Sanctum::actingAs($this->createUserWithFullAccess());
        Exceptions::fake();
        $this->ignoreCustomerNotifications();
        $this->makeLifecycleServiceFail();
        $booking = $this->createWavePaidBooking($this->createCustomer(Ambassador::factory()->create()), $this->createDepartWithBus(self::FIRST_DEPART_DATE), 'cos-transfer-5');
        $targetBus = $this->createDepartWithBus(self::SECOND_DEPART_DATE)->buses()->firstOrFail();

        $this->transferTo($booking, $targetBus)->assertOk();

        $this->assertSame($targetBus->depart_id, $booking->fresh()->depart_id);
        Exceptions::assertReported(RuntimeException::class);
    }

    // ---------------------------------------------------------------- end to end

    public function test_a_referred_customers_journey_from_payment_to_available_balance_and_back_on_refund(): void
    {
        Queue::fake([RecordTicketPaymentInCaisse::class]);
        $this->ignoreCustomerNotifications();
        $this->travelTo('2030-03-01 10:00:00');
        $ambassador = Ambassador::factory()->create();
        $balanceService = app(AmbassadorPerformanceService::class);
        $booking = $this->createUnpaidBooking($this->createCustomer($ambassador), $this->createDepartWithBus(self::FIRST_DEPART_DATE));

        app(BookingManager::class)->assignTicketToBooking($booking, 'wave');
        $balanceAfterPayment = $balanceService->getAccountBalance($ambassador);

        $this->travelTo('2030-03-10 12:00:00');
        app(AmbassadorEarningService::class)->approveDueEarnings();
        $balanceOnDepartureDay = $balanceService->getAccountBalance($ambassador);

        $this->travelTo('2030-03-11 08:00:00');
        app(AmbassadorEarningService::class)->approveDueEarnings();
        $balanceAfterWaitingPeriod = $balanceService->getAccountBalance($ambassador);

        $this->assertSame([500, 0, 500, 0], [$balanceAfterPayment->totalBalance, $balanceAfterPayment->availableBalance, $balanceAfterPayment->pendingBalance, $balanceAfterPayment->paidBalance]);
        $this->assertSame([500, 0, 500, 0], [$balanceOnDepartureDay->totalBalance, $balanceOnDepartureDay->availableBalance, $balanceOnDepartureDay->pendingBalance, $balanceOnDepartureDay->paidBalance]);
        $this->assertSame([500, 500, 0, 0], [$balanceAfterWaitingPeriod->totalBalance, $balanceAfterWaitingPeriod->availableBalance, $balanceAfterWaitingPeriod->pendingBalance, $balanceAfterWaitingPeriod->paidBalance]);
    }

    public function test_the_acquisition_amount_of_the_reward_rule_is_what_the_ambassador_earns(): void
    {
        Queue::fake([RecordTicketPaymentInCaisse::class]);
        AmbassadorRewardRule::query()->where('earning_type', AmbassadorEarningType::Acquisition)->delete();
        AmbassadorRewardRule::factory()->create(['amount' => 750]);
        $ambassador = Ambassador::factory()->create();
        $booking = $this->createUnpaidBooking($this->createCustomer($ambassador), $this->createDepartWithBus(self::FIRST_DEPART_DATE));

        app(BookingManager::class)->assignTicketToBooking($booking, 'wave');

        $this->assertSame(750, $this->earningsOf($ambassador)->sole()->amount);
    }
}
