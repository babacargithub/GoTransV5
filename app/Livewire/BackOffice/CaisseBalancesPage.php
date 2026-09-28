<?php

namespace App\Livewire\BackOffice;

use App\Enums\PermissionName;
use App\Http\Controllers\OrangeMoneyController;
use App\Http\Controllers\WavePaiementController;
use App\Livewire\BackOffice\Concerns\ManagesAccounts;
use App\Livewire\BackOffice\Concerns\ManagesCaisses;
use App\Models\Booking;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Back office "Caisses" page (Finance).
 *
 * Three tabs:
 * - "Aperçu": the original read-only dashboard — ticket revenue grouped by
 *   payment method for upcoming départs, plus live Wave/Orange Money merchant
 *   balances (each provider call fails independently so the page still
 *   renders the database figures).
 * - "Caisses": real till management — create/edit/delete, entrée/sortie de
 *   caisse, transfer between caisses, day lock/unlock, transactions.
 * - "Comptes": the generic Account ledger paired with Caisse via the
 *   SUM(accounts) == SUM(caisses) invariant enforced by AccountService.
 */
#[Layout('components.layouts.back-office')]
class CaisseBalancesPage extends Component
{
    use ManagesAccounts;
    use ManagesCaisses;

    /** @var 'apercu'|'caisses'|'comptes' */
    public string $activeTab = 'apercu';

    public ?string $flashStatusMessage = null;

    public ?string $flashErrorMessage = null;

    protected function resetFlashMessages(): void
    {
        $this->flashStatusMessage = null;
        $this->flashErrorMessage = null;
    }

    /**
     * Guard a permission-gated action. Returns false (and flashes an error)
     * when the current user lacks the permission; a `full-access` holder
     * always passes.
     */
    private function ensurePermittedOrFlash(PermissionName $requiredPermission): bool
    {
        if ($requiredPermission->allowedForCurrentUser()) {
            return true;
        }

        $this->flashErrorMessage = 'Action non autorisée : la permission « '.$requiredPermission->defaultLabel().' » est requise.';

        return false;
    }

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
