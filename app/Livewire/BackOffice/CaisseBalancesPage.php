<?php

namespace App\Livewire\BackOffice;

use App\Http\Controllers\OrangeMoneyController;
use App\Http\Controllers\WavePaiementController;
use App\Models\Booking;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Back office "Solde des caisses" page.
 *
 * Mirrors the legacy TicketController@index dashboard: ticket revenue grouped by
 * payment method for the current (upcoming) départs, plus the live Wave and
 * Orange Money merchant balances. The two provider balances are fetched through
 * the untouched controllers and each failure is caught independently so the page
 * still renders the (always-available) database figures.
 */
#[Layout('components.layouts.back-office')]
class CaisseBalancesPage extends Component
{
    /**
     * Ticket revenue per payment method for upcoming départs (same query as
     * TicketController@index).
     *
     * @return array<int, array{paymentMethod: string|null, total: float}>
     */
    #[Computed]
    public function paymentMethodBalances(): array
    {
        return Booking::query()
            ->join('tickets', 'bookings.ticket_id', '=', 'tickets.id')
            ->join('departs', 'bookings.depart_id', '=', 'departs.id')
            ->where('departs.date', '>', now())
            ->selectRaw('sum(tickets.price) as total, tickets.payment_method as paymentMethod')
            ->groupBy('tickets.payment_method')
            ->get()
            ->map(fn ($row): array => [
                'paymentMethod' => $row->paymentMethod,
                'total' => (float) $row->total,
            ])
            ->all();
    }

    public function paymentMethodBalancesTotal(): float
    {
        return collect($this->paymentMethodBalances)->sum('total');
    }

    /**
     * Live Wave merchant balance, or null when the Wave API is unreachable.
     */
    #[Computed]
    public function waveBalance(): ?int
    {
        try {
            return app(WavePaiementController::class)->getBalance();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Live Orange Money merchant balance, or null when the OM API is unreachable.
     */
    #[Computed]
    public function orangeMoneyBalance(): ?int
    {
        try {
            $balanceResponse = app(OrangeMoneyController::class)->balance();

            return (int) data_get($balanceResponse->getData(true), 'balance');
        } catch (\Throwable) {
            return null;
        }
    }

    public function render(): View
    {
        return view('livewire.back-office.caisse-balances-page')->title('Solde des caisses — Back Office');
    }
}
