<?php

namespace App\Services;

use App\Models\AppParams;
use App\Models\Booking;
use App\Models\Bus;
use App\Models\BusSeat;
use App\Models\Trajet;
use App\Utils\NotificationSender\SMSSender\SMSSender;
use Illuminate\Support\Collection;

/**
 * Single place for every outgoing SMS notification in the app. Callers gather whatever data a
 * message needs (a Booking, a Bus, a plain string, ...) and hand it to the matching method here;
 * this class owns message wording and recipient phone numbers.
 */
class NotificationService
{
    // Operations manager: bus-full/bus-closed alerts, unassignable group bookings.
    private const OPERATIONS_MANAGER_PHONE = '773300853';

    // GP/Yobuma terminal staff: new GP ticket purchases, unassignable group bookings.
    private const GP_TEAM_PHONE = '771273535';

    // Dispatch: near-full-bus heads-up sent as bookings come in (distinct from the manager number above).
    private const BUS_DISPATCH_PHONE = '771273535';

    // Brand a customer sees in SMS: GP bookings (Global Transports) vs every other customer, students included.
    private const GP_BRAND_NAME = 'Global Transports';

    private const STUDENT_BRAND_NAME = 'Globe One Transport';

    public function __construct(private readonly SMSSender $smsSender) {}

    private function isGpBooking(Booking $booking): bool
    {
        return is_request_for_gp_customers() || $booking->is_for_gp;
    }

    private function brandNameFor(Booking $booking): string
    {
        return $this->isGpBooking($booking) ? self::GP_BRAND_NAME : self::STUDENT_BRAND_NAME;
    }

    /**
     * Notifies a customer that a single booking's ticket payment succeeded.
     */
    public function notifyCustomerOfTicketPayment(Booking $booking, bool $online = true): void
    {
        $departName = $booking->depart->name;
        $seatNumber = $booking->depart->trajet_id == Trajet::UGB_DAKAR ? "\n Num siège :".$booking->seat_number.' ' :
            '';
        $schedule = $seatNumber."\n Heure:  ".$booking->formatted_schedule."\n Arret du bus ".
            $booking->point_dep->arret_bus;
        $defaultContactAgent = $this->isGpBooking($booking) ? 777794818 : AppParams::first()
            ->getBusAgentDefaultNumber();
        $contactAgent = $booking->bus->resolveAgentContactNumber($defaultContactAgent);
        $brandName = $this->brandNameFor($booking);
        $notificationMessageForOnlineUsers = "Vous avez acheté un ticket sur $brandName pour le départ $departName. RV: ".$schedule.",
         \nBus: ".$booking->bus->name.','.
            "\nContact du convoyeur qui sera dans le bus: ".$contactAgent;
        $notificationMessage = "Votre  ticket est enregistré sur $brandName pour $departName, paiement reçu. ".$booking->bus->name.','.
            $seatNumber.'
            RV '.$schedule.',
            Contact convoyeur du bus: '.$contactAgent;
        $message = $online ? $notificationMessageForOnlineUsers : $notificationMessage;
        $this->smsSender->sendSms($booking->customer->phone_number, $message);
    }

    /**
     * Notifies each customer in a group/multiple-booking payment that their ticket(s) are paid.
     *
     * A round trip creates two Booking rows per passenger (outbound + return leg) sharing the same
     * round_trip_id. Sending one message per Booking row — as this used to do — meant a round-trip
     * customer got two near-identical, confusing SMS. Bookings are grouped per passenger
     * (customer + round_trip_id) first, so a round-trip passenger gets a single message describing
     * both legs, while single-leg bookings keep the original one-message wording.
     */
    public function notifyCustomerOfGroupTicketPayment(Collection $bookings): void
    {
        if ($bookings->isEmpty()) {
            return;
        }

        $groups = $bookings->groupBy(fn (Booking $booking) => $booking->round_trip_id !== null
            ? 'roundtrip:'.$booking->customer_id.':'.$booking->round_trip_id
            : 'single:'.$booking->id);

        $messages = $groups->map(fn (Collection $bookingsForPassenger) => $this->buildGroupTicketPaymentMessage($bookingsForPassenger))
            ->values()
            ->all();

        $this->smsSender->sendMultipleSms($messages);
    }

    /**
     * @param  Collection<int, Booking>  $bookingsForPassenger  One or two bookings (outbound [+ return]) for the same passenger.
     * @return array{message: string, phone_number: string}
     */
    private function buildGroupTicketPaymentMessage(Collection $bookingsForPassenger): array
    {
        /** @var Booking $firstBooking */
        $firstBooking = $bookingsForPassenger->first();
        $defaultAgentNumber = AppParams::first()->getBusAgentDefaultNumber();

        if ($bookingsForPassenger->count() > 1) {
            // Outbound and return legs can run on different buses, each with its own field agent,
            // so the contact number is shown per leg rather than once for the whole message.
            $legsDescription = $bookingsForPassenger
                ->sortBy(fn (Booking $booking) => $booking->trip_leg === Booking::TRIP_LEG_RETURN ? 1 : 0)
                ->map(function (Booking $booking) use ($defaultAgentNumber) {
                    $legLabel = $booking->trip_leg === Booking::TRIP_LEG_RETURN ? 'Retour' : 'Aller';
                    $agentNumber = $booking->bus->resolveAgentContactNumber($defaultAgentNumber);

                    return "$legLabel: ".$booking->depart->name.', RV '.$booking->formatted_schedule
                        .' a '.$booking->point_dep->arret_bus.', Convoyeur '.$agentNumber;
                })
                ->implode(' | ');

            $message = 'Achat de ticket aller-retour réussi sur '.$this->brandNameFor($firstBooking).'. '.$legsDescription.'.';
        } else {
            $agentNumber = $firstBooking->bus->resolveAgentContactNumber($defaultAgentNumber);
            $message = 'Achat de ticket réussi sur '.$this->brandNameFor($firstBooking).'. Date voyage '
                .$firstBooking->depart->name
                .' RV '.$firstBooking->formatted_schedule
                .' A '.$firstBooking->point_dep->arret_bus.'. '
                ."\n Convoyeur ".$agentNumber;
        }

        return [
            'message' => $message,
            'phone_number' => $firstBooking->customer->phone_number,
        ];
    }

    /**
     * Notifies a customer that a booking was cancelled. When it was one leg of a round trip whose
     * other leg is still active (detached from the round trip but not cancelled), says so.
     */
    public function notifyCustomerOfBookingCancellation(Booking $booking, bool $otherLegStillActive = false): void
    {
        $message = 'Votre réservation sur Global Transports pour le départ '.$booking->depart->name
            .' a été annulée.';
        if ($otherLegStillActive) {
            $message .= ' Votre autre trajet (aller-retour) reste maintenu.';
        }
        $this->smsSender->sendSms($booking->customer->phone_number, $message);
    }

    /**
     * Notifies a customer that their booking was moved to a different bus/seat.
     */
    public function notifyCustomerOfBookingTransfer(Booking $booking, Bus $targetBus, BusSeat $targetSeat): void
    {
        $message = 'Votre réservation a été transférée sur le départ '.$targetBus->depart->name
            .' sur le bus '.$targetBus->name.' Nouveau Nº de siège '.$targetSeat->number
            .' Contact 771273535/771163003';
        $this->smsSender->sendSms(substr($booking->customer->phone_number, -9, 9), $message);
    }

    /**
     * Sends a customer the link to pay for their booking.
     */
    public function notifyCustomerOfPaymentLink(Booking $booking, string $paymentUrl): void
    {
        $message = 'Bnjr. Payez votre réservation Globe Transport sur le départ '.$booking->depart->name
            ."  sur ce lien : $paymentUrl";
        $this->smsSender->sendSms(substr($booking->customer->phone_number, -9, 9), $message);
    }

    /**
     * Sends an arbitrary, agent-authored message to a booking's customer (admin "send a note" action).
     */
    public function sendCustomMessageToCustomer(Booking $booking, string $message): ?bool
    {
        return $this->smsSender->sendSms(substr($booking->customer->phone_number, -9, 9), $message);
    }

    /**
     * Alerts the operations manager about a bus-related event (closed, full, ...). Caller builds
     * the message text; this just resolves and dispatches to the right recipient.
     */
    public function notifyManagerOfBusEvent(string $message): void
    {
        $this->smsSender->sendSms(self::OPERATIONS_MANAGER_PHONE, $message);
    }

    /**
     * Asks the operations manager to refund, by hand in the Wave app, one cancelled booking's share of a
     * payment that covered a whole group (Wave cannot partially refund through its API yet).
     *
     * @return bool False when the SMS provider reported a failure.
     */
    public function notifyManagerOfManualPartialRefund(
        Booking $cancelledBooking,
        string $waveReference,
        int $amountToRefund,
        int $groupPassengerCount,
        int $totalAmountPaid,
    ): bool {
        $message = 'REMBOURSEMENT WAVE MANUEL (paiement groupé, réservation annulée).'
            .$cancelledBooking->bus->full_name.' '
            ."\nTransaction id: ".($waveReference !== '' ? $waveReference : 'inconnue')
            ."\nMontant à rembourser: ".$amountToRefund.' F'
            ."\nTotal payé: ".$totalAmountPaid.' F'
            ."\nClient: ".normalize_passenger_display_name($cancelledBooking->customer->full_name)
            ."\nTel: ".$cancelledBooking->customer->phone_number
            ."\nPassagers du groupe: ".$groupPassengerCount;

        return $this->smsSender->sendSms(self::OPERATIONS_MANAGER_PHONE, $message) !== false;
    }

    /**
     * Alerts the GP/Yobuma terminal team about a bus or booking event they need to act on.
     */
    public function notifyGpTeamOfBusEvent(string $message): void
    {
        $this->smsSender->sendSms(self::GP_TEAM_PHONE, $message);
    }

    /**
     * Heads-up to dispatch that a bus is one seat away from full.
     */
    public function notifyDispatchOfBusAlmostFull(string $message): void
    {
        $this->smsSender->sendSms(self::BUS_DISPATCH_PHONE, $message);
    }
}
