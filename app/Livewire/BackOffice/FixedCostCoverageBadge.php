<?php

namespace App\Livewire\BackOffice;

use App\Data\Profit\FixedCostCoverageDTO;
use App\Services\FixedCostCoverageService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Back-office header badge, next to the caisse balance: are this month's planned fixed costs
 * covered yet? Neutral "non planifié" when no fixed cost was planned for the month. Read-only.
 */
class FixedCostCoverageBadge extends Component
{
    #[Computed]
    public function coverage(): FixedCostCoverageDTO
    {
        return app(FixedCostCoverageService::class)->coverageForMonth(now());
    }

    public function render(): View
    {
        return view('livewire.back-office.fixed-cost-coverage-badge');
    }
}
