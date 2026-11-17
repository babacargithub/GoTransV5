<?php

namespace App\Livewire\Ambassador;

use App\Livewire\Ambassador\Concerns\ResolvesLoggedInAmbassador;
use App\Services\AmbassadorPerformanceService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The payouts sent to the ambassador: amount, status and date.
 */
#[Layout('components.layouts.ambassador')]
class PayoutsPage extends Component
{
    use ResolvesLoggedInAmbassador;
    use WithPagination;

    #[Computed]
    public function payouts(): LengthAwarePaginator
    {
        return app(AmbassadorPerformanceService::class)->paginatePayouts($this->ambassador);
    }

    public function render(): View
    {
        return view('livewire.ambassador.payouts')->title('Mes paiements — Globe One Transport');
    }
}
