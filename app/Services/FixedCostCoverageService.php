<?php

namespace App\Services;

use App\Data\Profit\FixedCostCoverageDTO;
use App\Models\ExpenseType;
use App\Models\MonthlyExpenseBudget;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Tells whether a month's planned fixed costs (MonthlyExpenseBudget) are covered yet.
 *
 * Coverage is judged on the margin: revenue minus the variable costs, i.e. every expense that is
 * not absorbed by a planned fixed cost line (bus rentals, refunds, unplanned types, and any
 * amount paid beyond a line's budget). Revenue and expenses come from ProfitService, so tickets
 * of upcoming departs stay excluded until they have left.
 */
class FixedCostCoverageService
{
    public function __construct(private readonly ProfitService $profitService) {}

    public function coverageForMonth(CarbonInterface $month): FixedCostCoverageDTO
    {
        $monthStart = CarbonImmutable::instance($month)->startOfMonth();

        $budgetsByExpenseTypeId = MonthlyExpenseBudget::query()
            ->where('month', $monthStart->toDateString())
            ->where('amount', '>', 0)
            ->pluck('amount', 'expense_type_id');

        $profitReport = $this->profitService->computeProfit(
            dateFrom: $monthStart->toDateString(),
            dateTo: $monthStart->endOfMonth()->toDateString(),
        );

        $paidByExpenseTypeId = collect($profitReport->expensesByType)
            ->whereNotNull('expenseTypeId')
            ->pluck('amount', 'expenseTypeId');

        $expenseTypeNamesById = ExpenseType::query()
            ->whereIn('id', $budgetsByExpenseTypeId->keys())
            ->pluck('name', 'id');

        $lines = [];
        $absorbedByBudgets = 0;

        foreach ($budgetsByExpenseTypeId as $expenseTypeId => $budget) {
            $paid = (int) ($paidByExpenseTypeId[$expenseTypeId] ?? 0);
            $absorbedByBudgets += min($paid, $budget);

            $lines[] = [
                'expenseTypeId' => (int) $expenseTypeId,
                'name' => $expenseTypeNamesById[$expenseTypeId],
                'budget' => (int) $budget,
                'paid' => $paid,
                'remaining' => max(0, $budget - $paid),
                'overBudget' => max(0, $paid - $budget),
            ];
        }

        usort($lines, fn (array $first, array $second): int => $second['budget'] <=> $first['budget']);

        $fixedCostsTotal = (int) $budgetsByExpenseTypeId->sum();
        $variableCosts = $profitReport->totalExpenses - $absorbedByBudgets;
        $margin = $profitReport->totalRevenue - $variableCosts;

        return new FixedCostCoverageDTO(
            month: $monthStart,
            isPlanned: $fixedCostsTotal > 0,
            fixedCostsTotal: $fixedCostsTotal,
            totalRevenue: $profitReport->totalRevenue,
            variableCosts: $variableCosts,
            margin: $margin,
            remainingToCover: max(0, $fixedCostsTotal - $margin),
            isCovered: $fixedCostsTotal > 0 && $margin >= $fixedCostsTotal,
            coveragePercent: $fixedCostsTotal > 0 ? (int) max(0, min(100, floor($margin / $fixedCostsTotal * 100))) : 0,
            lines: $lines,
        );
    }
}
