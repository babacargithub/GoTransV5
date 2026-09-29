<?php

namespace App\Services;

use App\Data\Profit\ProfitReportDTO;
use App\Enums\AccountTransactionCategory;
use App\Models\Account;
use App\Models\AccountTransaction;

/**
 * Reads profit (revenue − expenses) straight off the persisted
 * AccountTransaction.category column — see AccountService::determineCategory()
 * for how each transaction gets classified as Revenue, Expense, or Internal
 * at creation time. No join or reference_type filtering needed here.
 */
class ProfitService
{
    public function computeProfit(?string $dateFrom = null, ?string $dateTo = null): ProfitReportDTO
    {
        $revenueByAccount = $this->sumByCategory(AccountTransactionCategory::Revenue, $dateFrom, $dateTo);
        $expensesByAccount = $this->sumByCategory(AccountTransactionCategory::Expense, $dateFrom, $dateTo);

        $totalRevenue = array_sum(array_column($revenueByAccount, 'amount'));
        $totalExpenses = array_sum(array_column($expensesByAccount, 'amount'));

        return new ProfitReportDTO(
            totalRevenue: $totalRevenue,
            totalExpenses: $totalExpenses,
            profit: $totalRevenue - $totalExpenses,
            revenueByAccount: $revenueByAccount,
            expensesByAccount: $expensesByAccount,
        );
    }

    /**
     * @return array<int, array{accountId: int, name: string, amount: int}>
     */
    private function sumByCategory(AccountTransactionCategory $category, ?string $dateFrom, ?string $dateTo): array
    {
        $query = AccountTransaction::query()->where('category', $category->value);

        if ($dateFrom !== null) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }

        if ($dateTo !== null) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        $amountsByAccountId = $query
            ->selectRaw('account_id, SUM(amount) as total')
            ->groupBy('account_id')
            ->pluck('total', 'account_id');

        if ($amountsByAccountId->isEmpty()) {
            return [];
        }

        return Account::query()
            ->whereIn('id', $amountsByAccountId->keys())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Account $account): array => [
                'accountId' => $account->id,
                'name' => $account->name,
                'amount' => (int) $amountsByAccountId[$account->id],
            ])
            ->values()
            ->all();
    }
}
