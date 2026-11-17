<?php

namespace App\Services;

use App\Data\Profit\ProfitReportDTO;
use App\Enums\AccountTransactionCategory;
use App\Enums\AccountTransactionType;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Booking;
use App\Models\DepartCostReport;
use App\Models\ExpenseType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for profit (revenue − expenses).
 *
 * Revenue = the ticket sales of the départs that have a cost report (départ date in range, the same
 * figure as the Bilans page) + every Revenue ledger entry that is not a ticket sale (colis, bus
 * coalisés, manual entrées…). Ticket sale ledger entries are ignored on the revenue side because the
 * cost reports already carry them, whenever they were paid.
 *
 * Expenses are read off the persisted AccountTransaction.category column — see
 * AccountService::determineCategory() for how each transaction gets classified as Revenue,
 * Expense, or Internal at creation time. Ticket refunds for departs that have not left yet are
 * provisional: they are excluded until the depart date has passed.
 */
class ProfitService
{
    private const TICKET_REFERENCE_TYPES = ['TICKET_SALE', 'MANUAL_TICKET_PAYMENT', 'TICKET_REFUND'];

    public function computeProfit(?string $dateFrom = null, ?string $dateTo = null): ProfitReportDTO
    {
        $revenueByAccount = $this->addTicketSalesOfCostReportedDeparts(
            $this->sumByCategory(AccountTransactionCategory::Revenue, $dateFrom, $dateTo, excludeTicketLedgerEntries: true),
            $dateFrom,
            $dateTo,
        );
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
     * Bookings holding a ticket, joined to their ticket, for the given départs — the "ticket sales"
     * definition shared with DepartController@ticketSales and the Bilans page.
     *
     * @param  array<int, int>  $departIds
     * @return Builder<Booking>
     */
    public function ticketSalesQueryForDeparts(array $departIds): Builder
    {
        return Booking::query()
            ->whereIn('bookings.depart_id', $departIds)
            ->whereNotNull('bookings.ticket_id')
            ->join('tickets', 'bookings.ticket_id', '=', 'tickets.id');
    }

    /**
     * Ticket sales of the existing départs that have a cost report and whose date is in the range.
     */
    public function ticketSalesOfCostReportedDeparts(?string $dateFrom = null, ?string $dateTo = null): int
    {
        $departIds = DepartCostReport::query()
            ->whereHas('depart', function (Builder $departs) use ($dateFrom, $dateTo): void {
                $departs
                    ->when($dateFrom !== null, fn (Builder $query) => $query->whereDate('date', '>=', $dateFrom))
                    ->when($dateTo !== null, fn (Builder $query) => $query->whereDate('date', '<=', $dateTo));
            })
            ->pluck('depart_id')
            ->all();

        return (int) $this->ticketSalesQueryForDeparts($departIds)->sum('tickets.price');
    }

    /**
     * @param  array<int, array{accountId: int, name: string, amount: int}>  $revenueByAccount
     * @return array<int, array{accountId: int, name: string, amount: int}>
     */
    private function addTicketSalesOfCostReportedDeparts(array $revenueByAccount, ?string $dateFrom, ?string $dateTo): array
    {
        $ticketSales = $this->ticketSalesOfCostReportedDeparts($dateFrom, $dateTo);

        if ($ticketSales === 0) {
            return $revenueByAccount;
        }

        $ticketSalesAccount = Account::query()->where('account_type', AccountType::TicketSales)->orderBy('id')->first(['id', 'name']);
        $ticketSalesAccountId = $ticketSalesAccount?->id ?? 0;

        foreach ($revenueByAccount as $index => $revenueRow) {
            if ($revenueRow['accountId'] === $ticketSalesAccountId) {
                $revenueByAccount[$index]['amount'] += $ticketSales;

                return $revenueByAccount;
            }
        }

        $revenueByAccount[] = ['accountId' => $ticketSalesAccountId, 'name' => $ticketSalesAccount?->name ?? 'Ventes de billets', 'amount' => $ticketSales];

        return $revenueByAccount;
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
            ->selectRaw('expense_type_id, SUM('.$this->signedAmountSql(AccountTransactionCategory::Expense).') as total')
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
     * The expense operations counted in expensesByType() for one expense type (null = "Non classé"),
     * newest first, so the listed amounts always add up to the report's figure.
     *
     * @return Collection<int, AccountTransaction>
     */
    public function expenseOperationsOfType(?int $expenseTypeId, ?string $dateFrom = null, ?string $dateTo = null): Collection
    {
        return $this->categoryQuery(AccountTransactionCategory::Expense, $dateFrom, $dateTo)
            ->where('expense_type_id', $expenseTypeId)
            ->with(['account', 'user'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return Builder<AccountTransaction>
     */
    private function categoryQuery(AccountTransactionCategory $category, ?string $dateFrom, ?string $dateTo, bool $excludeTicketLedgerEntries = false): Builder
    {
        $query = AccountTransaction::query()->where('category', $category->value);

        if ($excludeTicketLedgerEntries) {
            $query->where(fn (Builder $nonTicketQuery) => $nonTicketQuery
                ->whereNull('reference_type')
                ->orWhereNotIn('reference_type', self::TICKET_REFERENCE_TYPES));
        }

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
    private function sumByCategory(AccountTransactionCategory $category, ?string $dateFrom, ?string $dateTo, bool $excludeTicketLedgerEntries = false): array
    {
        $amountsByAccountId = $this->categoryQuery($category, $dateFrom, $dateTo, $excludeTicketLedgerEntries)
            ->selectRaw('account_id, SUM('.$this->signedAmountSql($category).') as total')
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
     * Amount signed by direction: a credit adds to a revenue and a debit adds to an expense, the
     * opposite direction takes away from it (e.g. a withdrawal wrongly tagged as revenue lowers
     * revenue instead of raising it).
     */
    private function signedAmountSql(AccountTransactionCategory $category): string
    {
        $addingTransactionType = $category === AccountTransactionCategory::Revenue
            ? AccountTransactionType::Credit
            : AccountTransactionType::Debit;

        return "CASE WHEN transaction_type = '{$addingTransactionType->value}' THEN amount ELSE -amount END";
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
