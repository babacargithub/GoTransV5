<?php

namespace App\Data\Profit;

readonly class ProfitReportDTO
{
    /**
     * @param  array<int, array{accountId: int, name: string, amount: int}>  $revenueByAccount
     * @param  array<int, array{accountId: int, name: string, amount: int}>  $expensesByAccount
     */
    public function __construct(
        public int $totalRevenue,
        public int $totalExpenses,
        public int $profit,
        public array $revenueByAccount,
        public array $expensesByAccount,
    ) {}
}
