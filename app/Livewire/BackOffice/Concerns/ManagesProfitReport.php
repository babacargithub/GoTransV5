<?php

namespace App\Livewire\BackOffice\Concerns;

use App\Services\ProfitService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

/**
 * "Revenus" back-office page (Finances): revenue vs. expenses vs.
 * net profit over a date range (default: current month), broken down by
 * account, straight off ProfitService::computeProfit().
 */
trait ManagesProfitReport
{
    #[Url(as: 'du')]
    public string $profitReportDateFrom = '';

    #[Url(as: 'au')]
    public string $profitReportDateTo = '';

    public function mountManagesProfitReport(): void
    {
        if ($this->profitReportDateFrom === '' && $this->profitReportDateTo === '') {
            $this->profitReportDateFrom = now()->startOfMonth()->toDateString();
            $this->profitReportDateTo = now()->endOfMonth()->toDateString();
        }
    }

    public function applyProfitReportFilter(): void
    {
        unset($this->profitReport);
    }

    /**
     * @return array{totalRevenue: int, totalExpenses: int, profit: int, revenueByAccount: array<int, array{accountId: int, name: string, amount: int}>, expensesByAccount: array<int, array{accountId: int, name: string, amount: int}>, expensesByType: array<int, array{expenseTypeId: int|null, name: string, amount: int}>}
     */
    #[Computed]
    public function profitReport(): array
    {
        $report = app(ProfitService::class)->computeProfit(
            dateFrom: $this->profitReportDateFrom !== '' ? $this->profitReportDateFrom : null,
            dateTo: $this->profitReportDateTo !== '' ? $this->profitReportDateTo : null,
        );

        return [
            'totalRevenue' => $report->totalRevenue,
            'totalExpenses' => $report->totalExpenses,
            'profit' => $report->profit,
            'revenueByAccount' => $report->revenueByAccount,
            'expensesByAccount' => $report->expensesByAccount,
            'expensesByType' => $report->expensesByType,
        ];
    }
}
