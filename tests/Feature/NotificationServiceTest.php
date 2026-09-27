<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Bus;
use App\Models\Customer;
use App\Models\Depart;
use App\Models\Destination;
use App\Models\HeureDepart;
use App\Models\PointDep;
use App\Models\Trajet;
use App\Services\NotificationService;
use App\Utils\NotificationSender\SMSSender\SMSSender;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NotificationServiceTest extends TestCase
{
    use DatabaseTransactions;

    private const GP_BRAND_NAME = 'Global Transports';

    private const STUDENT_BRAND_NAME = 'Globe One Transport';

    /**
     * @return array{depart: Depart, bus: Bus, pointDep: PointDep, destination: Destination}
     */
    private function createDepartWithBusAndPickupPoint(): array
    {
        $trajet = Trajet::create([
            'name' => 'Caravane '.uniqid(),
            'departure_city' => 'DAKAR',
            'arrival_city' => 'SAINT-LOUIS',
        ]);
        $depart = Depart::create([
            'name' => 'DEPART SMS '.uniqid(),
            'date' => now()->addDays(5),
            'trajet_id' => $trajet->id,
            'visibilite' => Depart::VISIBILITE_ALL_CUSTOMERS,
            'closed' => false,
            'locked' => false,
            'canceled' => false,
        ]);
        $bus = $depart->buses()->create([
            'name' => 'Bus SMS Test',
            'nombre_place' => 50,
            'ticket_price' => 4000,
            'gp_ticket_price' => 6000,
            'closed' => false,
        ]);
        $pointDep = PointDep::create([
            'name' => 'Gare routière '.uniqid(),
            'arret_bus' => 'Arret test',
            'trajet_id' => $trajet->id,
            'disabled' => false,
            'visibilite' => Depart::VISIBILITE_ALL_CUSTOMERS,
        ]);
        HeureDepart::create([
            'depart_id' => $depart->id,
            'bus_id' => $bus->id,
            'point_dep_id' => $pointDep->id,
            'heureDepart' => '07:30',
        ]);
        $destination = Destination::create(['name' => 'Dakar centre', 'trajet_id' => $trajet->id]);

        return ['depart' => $depart, 'bus' => $bus, 'pointDep' => $pointDep, 'destination' => $destination];
    }

    private function createBooking(array $departContext, bool $isForGp, array $extraAttributes = []): Booking
    {
        $customer = Customer::create([
            'prenom' => 'Awa',
            'nom' => 'Diop',
            'phone_number' => '77'.random_int(1000000, 9999999),
            'last_active' => now(),
        ]);

        return Booking::create(array_merge([
            'customer_id' => $customer->id,
            'depart_id' => $departContext['depart']->id,
            'bus_id' => $departContext['bus']->id,
            'point_dep_id' => $departContext['pointDep']->id,
            'destination_id' => $departContext['destination']->id,
            'paye' => true,
            'comment' => $isForGp ? 'for_gp' : null,
            'booked_with_platform' => 'website',
        ], $extraAttributes));
    }

    /**
     * @return array<int, array{message: string, phone_number: string}>
     */
    private function captureGroupSms(callable $notify): array
    {
        $sentMessages = [];
        $this->mock(SMSSender::class)
            ->shouldReceive('sendMultipleSms')
            ->once()
            ->andReturnUsing(function (array $messages) use (&$sentMessages): void {
                $sentMessages = $messages;
            });

        $notify(app(NotificationService::class));

        return $sentMessages;
    }

    private function captureSingleSms(callable $notify): string
    {
        $sentContent = '';
        $this->mock(SMSSender::class)
            ->shouldReceive('sendSms')
            ->once()
            ->andReturnUsing(function ($phoneNumber, $content) use (&$sentContent): bool {
                $sentContent = $content;

                return true;
            });

        $notify(app(NotificationService::class));

        return $sentContent;
    }

    public function test_group_payment_sms_names_globe_one_transport_for_a_student_booking(): void
    {
        $booking = $this->createBooking($this->createDepartWithBusAndPickupPoint(), isForGp: false);

        $sentMessages = $this->captureGroupSms(fn (NotificationService $service) => $service->notifyCustomerOfGroupTicketPayment(collect([$booking])));

        $this->assertStringContainsString('sur '.self::STUDENT_BRAND_NAME.'.', $sentMessages[0]['message']);
        $this->assertStringNotContainsString(self::GP_BRAND_NAME, $sentMessages[0]['message']);
    }

    public function test_group_payment_sms_names_global_transports_for_a_gp_booking(): void
    {
        $booking = $this->createBooking($this->createDepartWithBusAndPickupPoint(), isForGp: true);

        $sentMessages = $this->captureGroupSms(fn (NotificationService $service) => $service->notifyCustomerOfGroupTicketPayment(collect([$booking])));

        $this->assertStringContainsString('sur '.self::GP_BRAND_NAME.'.', $sentMessages[0]['message']);
        $this->assertStringNotContainsString(self::STUDENT_BRAND_NAME, $sentMessages[0]['message']);
    }

    public function test_round_trip_group_payment_sms_names_globe_one_transport_for_a_student_booking(): void
    {
        $departContext = $this->createDepartWithBusAndPickupPoint();
        $outboundBooking = $this->createBooking($departContext, isForGp: false, extraAttributes: [
            'round_trip_id' => 'round-trip-sms-test',
            'trip_leg' => Booking::TRIP_LEG_OUTBOUND,
        ]);
        $returnBooking = $this->createBooking($departContext, isForGp: false, extraAttributes: [
            'customer_id' => $outboundBooking->customer_id,
            'round_trip_id' => 'round-trip-sms-test',
            'trip_leg' => Booking::TRIP_LEG_RETURN,
        ]);

        $sentMessages = $this->captureGroupSms(fn (NotificationService $service) => $service->notifyCustomerOfGroupTicketPayment(collect([$outboundBooking, $returnBooking])));

        $this->assertCount(1, $sentMessages);
        $this->assertStringContainsString('aller-retour réussi sur '.self::STUDENT_BRAND_NAME.'.', $sentMessages[0]['message']);
        $this->assertStringNotContainsString(self::GP_BRAND_NAME, $sentMessages[0]['message']);
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function onlineAndOfflinePayments(): array
    {
        return [
            'online payment' => [true],
            'payment recorded by an agent' => [false],
        ];
    }

    #[DataProvider('onlineAndOfflinePayments')]
    public function test_single_booking_payment_sms_names_globe_one_transport_for_a_student_booking(bool $isOnlinePayment): void
    {
        $booking = $this->createBooking($this->createDepartWithBusAndPickupPoint(), isForGp: false);

        $sentContent = $this->captureSingleSms(fn (NotificationService $service) => $service->notifyCustomerOfTicketPayment($booking, $isOnlinePayment));

        $this->assertStringContainsString('sur '.self::STUDENT_BRAND_NAME.' pour', $sentContent);
        $this->assertStringNotContainsString(self::GP_BRAND_NAME, $sentContent);
    }

    #[DataProvider('onlineAndOfflinePayments')]
    public function test_single_booking_payment_sms_names_global_transports_and_gp_contact_for_a_gp_booking(bool $isOnlinePayment): void
    {
        $booking = $this->createBooking($this->createDepartWithBusAndPickupPoint(), isForGp: true);

        $sentContent = $this->captureSingleSms(fn (NotificationService $service) => $service->notifyCustomerOfTicketPayment($booking, $isOnlinePayment));

        $this->assertStringContainsString('sur '.self::GP_BRAND_NAME.' pour', $sentContent);
        $this->assertStringContainsString('777794818', $sentContent);
        $this->assertStringNotContainsString(self::STUDENT_BRAND_NAME, $sentContent);
    }
}
