<?php

namespace App\Livewire\BackOffice\Concerns;

use App\Services\ProfitService;
use Livewire\Attributes\Computed;

/**
 * "Revenus" tab of the back-office Finance page: revenue vs. expenses vs.
 * net profit over a date range (default: current month), broken down by
 * account, straight off ProfitService::computeProfit().
 */
trait ManagesProfitReport
{
    public string $profitReportDateFrom = '';

    public string $profitReportDateTo = '';

    public function mountManagesProfitReport(): void
    {
        $this->profitReportDateFrom = now()->startOfMonth()->toDateString();
        $this->profitReportDateTo = now()->endOfMonth()->toDateString();
    }

    public function applyProfitReportFilter(): void
    {
        unset($this->profitReport);
    }

    /**
     * @return array{totalRevenue: int, totalExpenses: int, profit: int, revenueByAccount: array<int, array{accountId: int, name: string, amount: int}>, expensesByAccount: array<int, array{accountId: int, name: string, amount: int}>}
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
        ];
    }
}
