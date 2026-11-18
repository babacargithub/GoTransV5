<?php

namespace App\Livewire\BackOffice;

use App\Models\Booking;
use App\Models\Caisse;
use App\Models\Depart;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Total caisse balance (sum across every caisse) shown in the back-office
 * header, next to the global search, on every page, together with the ticket
 * sales of upcoming départs (money not yet cashed) and the resulting "encaissé"
 * balance. Read-only — no mutations happen here.
 */
class TotalCaisseBalance extends Component
{
    #[Computed]
    public function totalBalance(): int
    {
        return (int) Caisse::sum('balance');
    }

    /**
     * Ticket sales (FCFA) of départs that have not left yet; same rule as
     * Depart::ticketSalesAmountByBusId (only bookings holding a ticket count).
     */
    #[Computed]
    public function upcomingTicketSales(): int
    {
        $upcomingDepartIds = Depart::query()
            ->where('date', '>', now())
            ->reorder()
            ->select('id');

        return (int) Booking::query()
            ->whereIn('bookings.depart_id', $upcomingDepartIds)
            ->whereNotNull('bookings.ticket_id')
            ->join('tickets', 'bookings.ticket_id', '=', 'tickets.id')
            ->sum('tickets.price');
    }

    #[Computed]
    public function cashedBalance(): int
    {
        return $this->totalBalance() - $this->upcomingTicketSales();
    }

    public function render(): View
    {
        return view('livewire.back-office.total-caisse-balance');
    }
}
