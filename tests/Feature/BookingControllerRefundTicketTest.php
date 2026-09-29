<?php

namespace Tests\Feature;

use App\Enums\AccountTransactionCategory;
use App\Enums\BookingType;
use App\Enums\CaisseCode;
use App\Enums\PermissionName;
use App\Jobs\RecordTicketPaymentInCaisse;
use App\Livewire\BackOffice\BusBookings;
use App\Manager\BookingManager;
use App\Manager\TicketManager;
use App\Models\AccountTransaction;
use App\Models\Booking;
use App\Models\Bus;
use App\Models\Caisse;
use App\Models\Customer;
use App\Models\Depart;
use App\Models\Destination;
use App\Models\HeureDepart;
use App\Models\PointDep;
use App\Models\Ticket;
use App\Models\TicketPayment;
use App\Models\Trajet;
use App\Services\AccountService;
use App\Utils\NotificationSender\SMSSender\SMSSender;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class BookingControllerRefundTicketTest extends TestCase
{
    use DatabaseTransactions;

    private const OPERATIONS_MANAGER_PHONE = '773300853';

    private const TICKET_PRICE = 4000;

    /**
     * @return array{bus: Bus, pointDep: PointDep, destination: Destination}
     */
    private function createBusWithPickupPoint(): array
    {
        $trajet = Trajet::create([
            'name' => 'Caravane '.uniqid(),
            'departure_city' => 'DAKAR',
            'arrival_city' => 'SAINT-LOUIS',
        ]);
        $depart = Depart::create([
            'name' => 'DEPART REFUND '.uniqid(),
            'date' => now()->addDays(5),
            'trajet_id' => $trajet->id,
            'closed' => false,
            'locked' => false,
            'canceled' => false,
        ]);
        $bus = $depart->buses()->create([
            'name' => 'Bus Refund Test',
            'nombre_place' => 50,
            'ticket_price' => self::TICKET_PRICE,
            'gp_ticket_price' => 6000,
            'closed' => false,
        ]);

        $pointDep = PointDep::create(['name' => 'Gare '.uniqid(), 'trajet_id' => $trajet->id]);
        HeureDepart::create([
            'depart_id' => $depart->id,
            'bus_id' => $bus->id,
            'point_dep_id' => $pointDep->id,
            'heureDepart' => '07:30',
        ]);

        return [
            'bus' => $bus,
            'pointDep' => $pointDep,
            'destination' => Destination::create(['name' => 'Dakar centre', 'trajet_id' => $trajet->id]),
        ];
    }

    /**
     * A booking paid by Wave: its own ticket carries the Wave checkout id, exactly as the webhook stores it.
     *
     * @param  array<string, mixed>  $bookingAttributes
     */
    private function createWavePaidBooking(array $busContext, string $checkoutId, array $bookingAttributes = [], ?Customer $customer = null): Booking
    {
        $ticket = new Ticket;
        $ticket->forceFill([
            'number' => random_int(1_000_000, 9_999_999_999),
            'price' => self::TICKET_PRICE,
            'payment_method' => 'wave',
            'comment' => $checkoutId,
            'used' => false,
            'soldBy' => 'system',
            'soldAt' => now(),
            'expiryDate' => now()->addDays(30),
        ])->save();

        $customer ??= Customer::create([
            'prenom' => 'Awa',
            'nom' => 'Diop',
            'phone_number' => '77'.random_int(1000000, 9999999),
            'last_active' => now(),
        ]);

        return Booking::create(array_merge([
            'customer_id' => $customer->id,
            'depart_id' => $busContext['bus']->depart_id,
            'bus_id' => $busContext['bus']->id,
            'point_dep_id' => $busContext['pointDep']->id,
            'destination_id' => $busContext['destination']->id,
            'ticket_id' => $ticket->id,
            'paye' => true,
        ], $bookingAttributes));
    }

    /**
     * Three passengers who paid together in one Wave transaction of 3 x 4000.
     *
     * @return array<int, Booking>
     */
    private function createGroupPaidInOneWaveTransaction(string $checkoutId): array
    {
        $busContext = $this->createBusWithPickupPoint();
        $groupId = BookingManager::generateBookingGroupId();
        TicketPayment::create([
            'payement_method' => 'wave',
            'group_id' => $groupId,
            'is_for_multiple_booking' => true,
            'status' => TicketPayment::STATUS_SUCCESS,
            'montant' => 3 * self::TICKET_PRICE,
        ]);

        return collect(range(0, 2))->map(fn (int $passengerIndex): Booking => $this->createWavePaidBooking($busContext, $checkoutId, [
            'group_id' => $groupId,
            'booking_type' => BookingType::Group,
            'is_main_booking' => $passengerIndex === 0,
        ]))->all();
    }

    private function actingAsUserAllowedToRefund(): void
    {
        Sanctum::actingAs($this->createUserWithPermissions([PermissionName::RefundTicket->value]));
    }

    private function fakeWaveLookupOfTransactionId(string $checkoutId, string $transactionId): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.wave.com/v1/checkout/sessions/'.$checkoutId => Http::response(['id' => $checkoutId, 'transaction_id' => $transactionId]),
        ]);
    }

    private function assertNoWaveRefundWasRequested(): void
    {
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/refund'));
    }

    public function test_refunding_a_group_booking_cancels_only_that_booking_and_texts_the_manager_the_manual_refund_details(): void
    {
        $this->fakeWaveLookupOfTransactionId('cos-group-1', 'TX-GROUP-1');
        [$bookingToRefund, $secondPassengerBooking, $thirdPassengerBooking] = $this->createGroupPaidInOneWaveTransaction('cos-group-1');
        $this->actingAsUserAllowedToRefund();
        $sentSms = [];
        $this->mock(SMSSender::class)
            ->shouldReceive('sendSms')
            ->once()
            ->andReturnUsing(function ($phoneNumber, $message) use (&$sentSms): bool {
                $sentSms = ['phoneNumber' => $phoneNumber, 'message' => $message];

                return true;
            });

        $response = $this->postJson("/api/bookings/{$bookingToRefund->id}/refund");

        $response->assertOk()->assertJsonPath('manualRefundRequested', true);
        $this->assertSoftDeleted($bookingToRefund);
        $this->assertNotSoftDeleted($secondPassengerBooking);
        $this->assertNotSoftDeleted($thirdPassengerBooking);
        $this->assertNoWaveRefundWasRequested();
        $this->assertStringContainsString('Transaction: TX-GROUP-1', $sentSms['message']);
        $this->assertStringContainsString('Montant à rembourser: 4000 F', $sentSms['message']);
        $this->assertStringContainsString('Client: Awa DIOP', $sentSms['message']);
        $this->assertStringContainsString('Tel: '.$bookingToRefund->customer->phone_number, $sentSms['message']);
        $this->assertStringContainsString('Passagers du groupe: 3', $sentSms['message']);
        $this->assertStringContainsString('Total payé: 12000 F', $sentSms['message']);
        $this->assertSame(self::OPERATIONS_MANAGER_PHONE, $sentSms['phoneNumber']);
    }

    public function test_the_manual_refund_sms_falls_back_to_the_checkout_id_when_wave_cannot_return_the_transaction_id(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.wave.com/v1/checkout/sessions/cos-group-2' => Http::response([], 500)]);
        [$bookingToRefund] = $this->createGroupPaidInOneWaveTransaction('cos-group-2');
        $this->actingAsUserAllowedToRefund();
        $sentMessage = '';
        $this->mock(SMSSender::class)
            ->shouldReceive('sendSms')
            ->once()
            ->andReturnUsing(function ($phoneNumber, $message) use (&$sentMessage): bool {
                $sentMessage = $message;

                return true;
            });

        $this->postJson("/api/bookings/{$bookingToRefund->id}/refund")->assertOk();

        $this->assertStringContainsString('Transaction: cos-group-2', $sentMessage);
    }

    public function test_refunding_a_booking_paid_alone_still_refunds_the_wave_transaction_without_texting_the_manager(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.wave.com/v1/checkout/sessions/cos-alone-1/refund' => Http::response([], 200)]);
        $booking = $this->createWavePaidBooking($this->createBusWithPickupPoint(), 'cos-alone-1');
        $this->actingAsUserAllowedToRefund();
        $this->mock(SMSSender::class)->shouldNotReceive('sendSms');

        $this->postJson("/api/bookings/{$booking->id}/refund")->assertOk();

        $this->assertSoftDeleted($booking);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.wave.com/v1/checkout/sessions/cos-alone-1/refund');
    }

    public function test_refunding_a_booking_paid_alone_reverses_the_original_ticket_sale_ledger_entry(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.wave.com/v1/checkout/sessions/cos-alone-ledger/refund' => Http::response([], 200)]);
        $booking = $this->createWavePaidBooking($this->createBusWithPickupPoint(), 'cos-alone-ledger');
        RecordTicketPaymentInCaisse::dispatchSync($booking->ticket->id);
        $waveCaisse = Caisse::findByCode(CaisseCode::Wave);
        $ticketSalesAccount = app(AccountService::class)->getOrCreateTicketSalesAccount();
        $waveBalanceAfterSale = $waveCaisse->fresh()->balance;
        $accountBalanceAfterSale = $ticketSalesAccount->fresh()->balance;
        $this->actingAsUserAllowedToRefund();
        $this->mock(SMSSender::class)->shouldNotReceive('sendSms');

        $this->postJson("/api/bookings/{$booking->id}/refund")->assertOk();

        // Wave nets its fee off the deposit, so the amount actually credited
        // (and now reversed) is less than the ticket's full price.
        $netAmount = (int) round(self::TICKET_PRICE / (1 + TicketManager::WAVE_FEES));

        $reversal = AccountTransaction::where('reference_type', 'TICKET_REFUND')->where('reference_id', $booking->ticket->id)->first();
        $this->assertNotNull($reversal);
        $this->assertSame(AccountTransactionCategory::Expense, $reversal->category);
        $this->assertSame($netAmount, $reversal->amount);
        $this->assertSame($waveBalanceAfterSale - $netAmount, $waveCaisse->fresh()->balance);
        $this->assertSame($accountBalanceAfterSale - $netAmount, $ticketSalesAccount->fresh()->balance);
    }

    public function test_refunding_a_group_booking_also_reverses_that_bookings_ticket_sale_ledger_entry_only(): void
    {
        $this->fakeWaveLookupOfTransactionId('cos-group-ledger', 'TX-GROUP-LEDGER');
        [$bookingToRefund, $secondPassengerBooking] = $this->createGroupPaidInOneWaveTransaction('cos-group-ledger');
        RecordTicketPaymentInCaisse::dispatchSync($bookingToRefund->ticket->id);
        RecordTicketPaymentInCaisse::dispatchSync($secondPassengerBooking->ticket->id);
        $this->actingAsUserAllowedToRefund();
        $this->mock(SMSSender::class)->shouldReceive('sendSms')->once()->andReturn(true);

        $this->postJson("/api/bookings/{$bookingToRefund->id}/refund")->assertOk();

        $this->assertSame(
            1,
            AccountTransaction::where('reference_type', 'TICKET_REFUND')->where('reference_id', $bookingToRefund->ticket->id)->count(),
        );
        $this->assertSame(
            0,
            AccountTransaction::where('reference_type', 'TICKET_REFUND')->where('reference_id', $secondPassengerBooking->ticket->id)->count(),
        );
    }

    public function test_refunding_one_leg_of_a_lone_travellers_round_trip_is_manual_because_both_legs_share_the_payment(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.wave.com/v1/checkout/sessions/cos-roundtrip-1' => Http::response([], 500)]);
        $busContext = $this->createBusWithPickupPoint();
        $groupId = BookingManager::generateBookingGroupId();
        TicketPayment::create([
            'payement_method' => 'wave',
            'group_id' => $groupId,
            'is_for_multiple_booking' => true,
            'status' => TicketPayment::STATUS_SUCCESS,
            'montant' => 2 * self::TICKET_PRICE,
        ]);
        $outboundBooking = $this->createWavePaidBooking($busContext, 'cos-roundtrip-1', [
            'group_id' => $groupId,
            'round_trip_id' => 'round-trip-refund-test',
            'trip_leg' => Booking::TRIP_LEG_OUTBOUND,
        ]);
        $returnBooking = $this->createWavePaidBooking($busContext, 'cos-roundtrip-1', [
            'group_id' => $groupId,
            'round_trip_id' => 'round-trip-refund-test',
            'trip_leg' => Booking::TRIP_LEG_RETURN,
        ], $outboundBooking->customer);
        $this->actingAsUserAllowedToRefund();
        $sentMessage = '';
        $this->mock(SMSSender::class)
            ->shouldReceive('sendSms')
            ->once()
            ->andReturnUsing(function ($phoneNumber, $message) use (&$sentMessage): bool {
                $sentMessage = $message;

                return true;
            });

        $this->postJson("/api/bookings/{$returnBooking->id}/refund")->assertOk()->assertJsonPath('manualRefundRequested', true);

        $this->assertSoftDeleted($returnBooking);
        $this->assertNotSoftDeleted($outboundBooking);
        $this->assertNoWaveRefundWasRequested();
        $this->assertStringContainsString('Passagers du groupe: 1', $sentMessage);
        $this->assertStringContainsString('Total payé: 8000 F', $sentMessage);
    }

    public function test_the_response_tells_the_agent_to_warn_the_manager_when_the_sms_cannot_be_sent(): void
    {
        $this->fakeWaveLookupOfTransactionId('cos-group-3', 'TX-GROUP-3');
        [$bookingToRefund] = $this->createGroupPaidInOneWaveTransaction('cos-group-3');
        $this->actingAsUserAllowedToRefund();
        $this->mock(SMSSender::class)->shouldReceive('sendSms')->once()->andReturn(false);

        $response = $this->postJson("/api/bookings/{$bookingToRefund->id}/refund");

        $response->assertOk()->assertJsonPath('manualRefundRequested', true);
        $this->assertStringContainsString("n'a pas pu être envoyé", $response->json('message'));
        $this->assertSoftDeleted($bookingToRefund);
    }

    public function test_the_back_office_tells_the_agent_the_group_refund_is_manual(): void
    {
        $this->fakeWaveLookupOfTransactionId('cos-group-4', 'TX-GROUP-4');
        [$bookingToRefund] = $this->createGroupPaidInOneWaveTransaction('cos-group-4');
        $this->mock(SMSSender::class)->shouldReceive('sendSms')->once()->andReturn(true);

        Livewire::actingAs($this->createUserWithPermissions([PermissionName::RefundTicket->value]))
            ->test(BusBookings::class, ['bus' => $bookingToRefund->bus])
            ->call('askToConfirmRefund', $bookingToRefund->id)
            ->call('confirmPendingAction')
            ->assertSet('flashStatusMessage', "Réservation annulée. Elle a été payée avec d'autres réservations : le remboursement Wave doit être fait manuellement, le responsable a été prévenu par SMS.");
    }
}
