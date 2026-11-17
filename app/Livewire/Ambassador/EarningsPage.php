<?php

namespace App\Livewire\Ambassador;

use App\Enums\AmbassadorEarningStatus;
use App\Livewire\Ambassador\Concerns\ResolvesLoggedInAmbassador;
use App\Services\AmbassadorPerformanceService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The ambassador's earnings ledger: every line with its type, status and approval date.
 */
#[Layout('components.layouts.ambassador')]
class EarningsPage extends Component
{
    use ResolvesLoggedInAmbassador;
    use WithPagination;

    #[Url(as: 'statut')]
    public string $statusFilter = '';

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function earnings(): LengthAwarePaginator
    {
        return app(AmbassadorPerformanceService::class)->paginateEarnings(
            $this->ambassador,
            AmbassadorEarningStatus::tryFrom($this->statusFilter),
        );
    }

    public function render(): View
    {
        return view('livewire.ambassador.earnings')->title('Mes gains — Globe One Transport');
    }
}
