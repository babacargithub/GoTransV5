<?php

namespace App\Observers;

use App\Models\Booking;
use App\Utils\NotificationSender\SMSSender\SMSSender;

class BookingObserver
{
    /**
     * Handle the Booking "created" event.
     */
    public function created(Booking $booking): void
    {
        //
        $this->updateCustomerLastActivity($booking);
        // check if bus is full and closes it if yes
        $this->checkIfBusIsFullAndClosesItIfYes($booking);

    }

    /**
     * Handle the Booking "updated" event.
     */
    public function updated(Booking $booking): void
    {
        //
        $this->updateCustomerLastActivity($booking);
        // check if bus is full and closes it if yes
        $this->checkIfBusIsFullAndClosesItIfYes($booking);
    }

    /**
     * Handle the Booking "deleted" event.
     */
    public function deleted(Booking $booking): void
    {
        //
//        $this->updateCustomerLastActivity($booking);

    }

    /**
     * Handle the Booking "restored" event.
     */
    public function restored(Booking $booking): void
    {
        //
        $this->updateCustomerLastActivity($booking);
        $this->checkIfBusIsFullAndClosesItIfYes($booking);
    }

    /**
     * Handle the Booking "force deleted" event.
     */
    public function forceDeleted(Booking $booking): void
    {
        //
    }

    /**
     * @param Booking $booking
     * @return void
     */
    public function updateCustomerLastActivity(Booking $booking): void
    {
        $customer = $booking->customer;
        $customer->updateLastActivity();
        $customer->save();
    }

    /**
     * @param Booking $booking
     * @return void
     */
    public function checkIfBusIsFullAndClosesItIfYes(Booking $booking): void
    {
        if (($booking->bus->nombre_place - $booking->bus->numberOfTicketsSold()) == 1) {
            $smsSender = app(SMSSender::class);
            $smsSender->sendSms(773333333, "Le bus " . $booking->bus->full_name . " est arrivé à "
                . $booking->bus->numberOfTicketsSold());
        }
        if ($booking->bus->nombre_place == $booking->bus->numberOfTicketsSold()) {
            $bus = $booking->bus;
            $bus->close();
            $bus->save();

        }
    }
}
