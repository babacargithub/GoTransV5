<?php

namespace App\Services;

use App\Models\Bus;
use App\Models\Customer;
use App\Models\Depart;
use App\Models\WaitingCustomer;

class WaitingCustomerService
{
    const REASON_BUS_FULL = "bus_full";
    const REASON_BUS_CLOSED = "bus_closed";
    const REASON_DEPART_CLOSED = "depart_closed";
    const REASON_NO_BUS_AVAILABLE = "no_bus_available";

    /**
     * Keeps track of a customer whose booking failed because the targeted bus/depart is full or
     * closed, so the depart can't silently lose the lead: staff can later reach out (e.g. once a
     * new depart opens on the same trajet) instead of the customer simply disappearing.
     *
     * A customer can end up with several waiting_customers rows for the same bus/depart (one per
     * failed attempt) - that's fine, each row is a record of one failed booking attempt. $data is
     * the request's validated() array (the attempted booking payload), with "reason" merged in.
     */
    public function recordFailedBooking(
        Customer $customer,
        Depart $depart,
        ?Bus $bus,
        string $reason,
        array $data
    ): WaitingCustomer {
        return WaitingCustomer::create([
            "customer_id" => $customer->id,
            "depart_id" => $depart->id,
            "bus_id" => $bus?->id,
            "data" => array_merge(["reason" => $reason], $data),
        ]);
    }
}
