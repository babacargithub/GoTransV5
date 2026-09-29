<?php

namespace App\Enums;

enum BookingTransferType: string
{
    /** BookingController::transferBooking — a single booking moved on its own. */
    case Individual = 'INDIVIDUAL';

    /** BusManager::transferBookings — a whole batch of bookings moved together. */
    case Bulk = 'BULK';
}
