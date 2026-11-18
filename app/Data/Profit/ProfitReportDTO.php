<?php

namespace App\Data\Profit;

readonly class ProfitReportDTO
{
    /**
     * @param  array<int, array{accountId: int, name: string, amount: int}>  $revenueByAccount
     * @param  array<int, array{accountId: int, name: string, amount: int}>  $expensesByAccount
     * @param  array<int, array{expenseTypeId: int|null, name: string, amount: int}>  $expensesByType
     */
    public function __construct(
        public int $totalRevenue,
        public int $totalExpenses,
        public int $profit,
        public array $revenueByAccount,
        public array $expensesByAccount,
        public array $expensesByType = [],
    ) {}
}
