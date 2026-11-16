<?php

namespace App\Services;

use App\Data\Profit\ProfitReportDTO;
use App\Enums\AccountTransactionCategory;
use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\ExpenseType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Reads profit (revenue − expenses) straight off the persisted
 * AccountTransaction.category column — see AccountService::determineCategory()
 * for how each transaction gets classified as Revenue, Expense, or Internal
 * at creation time.
 *
 * Ticket sales (and their refunds) for departs that have not left yet are
 * provisional: they are excluded until the depart date has passed.
 */
class ProfitService
{
    private const TICKET_REFERENCE_TYPES = ['TICKET_SALE', 'MANUAL_TICKET_PAYMENT', 'TICKET_REFUND'];

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
            expensesByType: $this->sumExpensesByType($dateFrom, $dateTo),
        );
    }

    /**
     * Expenses grouped by expense type, biggest first. Expenses without a type
     * (historical rows that could not be classified) are gathered in a single
     * trailing "Non classé" row whose expenseTypeId is null.
     *
     * @return array<int, array{expenseTypeId: int|null, name: string, amount: int}>
     */
    private function sumExpensesByType(?string $dateFrom, ?string $dateTo): array
    {
        $amountsByExpenseTypeId = $this->categoryQuery(AccountTransactionCategory::Expense, $dateFrom, $dateTo)
            ->selectRaw('expense_type_id, SUM(amount) as total')
            ->groupBy('expense_type_id')
            ->pluck('total', 'expense_type_id');

        $expenseTypeNamesById = ExpenseType::query()
            ->whereIn('id', $amountsByExpenseTypeId->keys()->filter())
            ->pluck('name', 'id');

        $classifiedRows = $amountsByExpenseTypeId
            ->reject(fn ($total, $expenseTypeId): bool => $expenseTypeId === '' || $expenseTypeId === null)
            ->map(fn ($total, $expenseTypeId): array => [
                'expenseTypeId' => (int) $expenseTypeId,
                'name' => $expenseTypeNamesById[$expenseTypeId],
                'amount' => (int) $total,
            ])
            ->sortByDesc('amount')
            ->values()
            ->all();

        $unclassifiedAmount = (int) ($amountsByExpenseTypeId[''] ?? 0);

        if ($unclassifiedAmount > 0) {
            $classifiedRows[] = ['expenseTypeId' => null, 'name' => 'Non classé', 'amount' => $unclassifiedAmount];
        }

        return $classifiedRows;
    }

    /**
     * @return Builder<AccountTransaction>
     */
    private function categoryQuery(AccountTransactionCategory $category, ?string $dateFrom, ?string $dateTo): Builder
    {
        $query = AccountTransaction::query()->where('category', $category->value);
        $this->excludeTransactionsOfUpcomingDeparts($query);

        if ($dateFrom !== null) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }

        if ($dateTo !== null) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        return $query;
    }

    /**
     * @return array<int, array{accountId: int, name: string, amount: int}>
     */
    private function sumByCategory(AccountTransactionCategory $category, ?string $dateFrom, ?string $dateTo): array
    {
        $amountsByAccountId = $this->categoryQuery($category, $dateFrom, $dateTo)
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

    /**
     * @param  Builder<AccountTransaction>  $query
     */
    private function excludeTransactionsOfUpcomingDeparts(Builder $query): void
    {
        $query->whereNot(function (Builder $ticketTransactionQuery): void {
            $ticketTransactionQuery
                ->whereIn('reference_type', self::TICKET_REFERENCE_TYPES)
                ->whereExists(function ($upcomingBookingQuery): void {
                    $upcomingBookingQuery
                        ->select(DB::raw(1))
                        ->from('bookings')
                        ->join('departs', 'departs.id', '=', 'bookings.depart_id')
                        ->whereColumn('bookings.ticket_id', 'account_transactions.reference_id')
                        ->where('departs.date', '>', now());
                });
        });
    }
}
