<?php

namespace App\Livewire\BackOffice;

use App\Http\Controllers\OrangeMoneyController;
use App\Http\Controllers\WavePaiementController;
use App\Livewire\BackOffice\Concerns\FlashesStatusAndGuardsPermissions;
use App\Livewire\BackOffice\Concerns\ManagesAccounts;
use App\Livewire\BackOffice\Concerns\ManagesCaisses;
use App\Models\Booking;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Back office "Caisses" page (Finance).
 *
 * Two tabs:
 * - "Aperçu": the original read-only dashboard — ticket revenue grouped by
 *   payment method for upcoming départs, plus live Wave/Orange Money merchant
 *   balances (each provider call fails independently so the page still
 *   renders the database figures).
 * - "Caisses": real till management — create/edit/delete, entrée/sortie de
 *   caisse, transfer between caisses, day lock/unlock, transactions.
 */
#[Layout('components.layouts.back-office')]
class CaisseBalancesPage extends Component
{
    use FlashesStatusAndGuardsPermissions;
    use ManagesAccounts;
    use ManagesCaisses;

    /** @var 'apercu'|'caisses' */
    #[Url(as: 'onglet')]
    public string $activeTab = 'apercu';

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

    #[Computed]
    public function waveBalance(): ?int
    {
        try {
            return app(WavePaiementController::class)->getBalance();
        } catch (\Throwable) {
            return null;
        }
    }

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
        return view('livewire.back-office.caisse-balances-page')->title('Caisses — Back Office');
    }
}
