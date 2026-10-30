<?php

namespace App\Livewire\Ambassador;

use App\Data\Ambassador\AmbassadorBalanceDTO;
use App\Models\Ambassador;
use App\Services\AmbassadorPerformanceService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Ambassador space home page: who they are, their account balance, their code and contract status.
 */
#[Layout('components.layouts.ambassador')]
class Home extends Component
{
    #[Computed]
    public function ambassador(): Ambassador
    {
        return Ambassador::query()
            ->where('user_account_id', auth()->id())
            ->firstOrFail();
    }

    #[Computed]
    public function accountBalance(): AmbassadorBalanceDTO
    {
        return app(AmbassadorPerformanceService::class)->getAccountBalance($this->ambassador);
    }

    #[Computed]
    public function acquiredCustomersCount(): int
    {
        return app(AmbassadorPerformanceService::class)->countAcquiredCustomers($this->ambassador);
    }

    public function render(): View
    {
        return view('livewire.ambassador.home')->title('Mon espace ambassadeur — Globe One Transport');
    }
}
