<?php

namespace App\Data\Profit;

use Carbon\CarbonImmutable;

/**
 * Whether one month's planned fixed costs are covered yet. Coverage compares the month's margin
 * (revenue minus every cost that is not a planned fixed cost) with the planned fixed costs total.
 */
readonly class FixedCostCoverageDTO
{
    /**
     * @param  array<int, array{expenseTypeId: int, name: string, budget: int, paid: int, remaining: int, overBudget: int}>  $lines
     */
    public function __construct(
        public CarbonImmutable $month,
        public bool $isPlanned,
        public int $fixedCostsTotal,
        public int $totalRevenue,
        public int $variableCosts,
        public int $margin,
        public int $remainingToCover,
        public bool $isCovered,
        public int $coveragePercent,
        public array $lines,
    ) {}
}
