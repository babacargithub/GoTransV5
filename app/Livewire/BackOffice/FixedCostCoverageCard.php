<?php

namespace App\Livewire\BackOffice;

use App\Data\Profit\FixedCostCoverageDTO;
use App\Services\FixedCostCoverageService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * "Charges fixes du mois" card of the Revenus page: are the planned fixed costs of the chosen
 * month covered by that month's margin yet? Defaults to the current month; past months can be
 * reviewed with the budgets that were planned for them. See FixedCostCoverageService.
 */
class FixedCostCoverageCard extends Component
{
    private const PAST_MONTHS_COUNT = 11;

    private const FUTURE_MONTHS_COUNT = 6;

    #[Url(as: 'mois')]
    public string $coverageMonthKey = '';

    public function mount(): void
    {
        if (! array_key_exists($this->coverageMonthKey, $this->selectableMonths())) {
            $this->coverageMonthKey = CarbonImmutable::now()->format('Y-m');
        }
    }

    /**
     * Months that can be reviewed, keyed by "Y-m", labelled in French.
     *
     * @return array<string, string>
     */
    public function selectableMonths(): array
    {
        $currentMonth = CarbonImmutable::now()->startOfMonth();
        $monthLabelsByKey = [];

        for ($monthOffset = -self::PAST_MONTHS_COUNT; $monthOffset <= self::FUTURE_MONTHS_COUNT; $monthOffset++) {
            $month = $currentMonth->addMonths($monthOffset);
            $monthLabelsByKey[$month->format('Y-m')] = ucfirst($month->locale('fr')->translatedFormat('F Y'));
        }

        return $monthLabelsByKey;
    }

    #[Computed]
    public function coverage(): FixedCostCoverageDTO
    {
        $selectedMonth = array_key_exists($this->coverageMonthKey, $this->selectableMonths())
            ? CarbonImmutable::createFromFormat('Y-m-d', $this->coverageMonthKey.'-01')
            : CarbonImmutable::now();

        return app(FixedCostCoverageService::class)->coverageForMonth($selectedMonth);
    }

    public function render(): View
    {
        return view('livewire.back-office.fixed-cost-coverage-card');
    }
}
