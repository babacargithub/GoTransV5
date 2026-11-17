<?php

namespace App\Services;

use App\Enums\AccountTransactionType;
use App\Exceptions\InsufficientAccountBalanceException;
use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\DepartCostReport;
use App\Models\MonthlyExpenseBudget;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Moves the profit of a départ (ticket sales minus bus rental fees) out of "Ventes de billets":
 * first into the "Charges" account until the month's planned costs (MonthlyExpenseBudget) are
 * reached, then into "Bénéfices".
 *
 * Only a départ that has already left is allocated, since its sales can still change before.
 * The allocation is reconciled, not appended: every call recomputes what the report should hold
 * and posts only the difference with what it already holds, so saving a report again (or editing
 * it) can never double-deposit, and a lowered profit gives money back to "Ventes de billets".
 * A report is never topped up by a later edit of another report of the same month.
 */
class DepartProfitAllocationService
{
    public const CHARGES_ACCOUNT_NAME = 'Charges';

    public const BENEFITS_ACCOUNT_NAME = 'Bénéfices';

    public const REFERENCE_TYPE = 'DEPART_PROFIT_ALLOCATION';

    public function __construct(private readonly AccountService $accountService) {}

    /**
     * @return array{charges: int, benefits: int}|null the amounts the report now holds, null when the départ has not left yet
     *
     * @throws RuntimeException when the Charges or Bénéfices account does not exist
     * @throws InsufficientAccountBalanceException when giving back money an account has already spent
     */
    public function allocate(DepartCostReport $departCostReport, ?int $userId = null): ?array
    {
        return DB::transaction(function () use ($departCostReport, $userId): ?array {
            $lockedCostReport = DepartCostReport::query()
                ->whereKey($departCostReport->id)
                ->lockForUpdate()
                ->with(['depart', 'busRentals'])
                ->firstOrFail();
            $depart = $lockedCostReport->depart;

            if (! $depart->isPassed()) {
                return null;
            }

            $ticketSalesAccount = $this->accountService->getOrCreateTicketSalesAccount();
            $chargesAccount = $this->lockedAccountNamed(self::CHARGES_ACCOUNT_NAME);
            $benefitsAccount = $this->lockedAccountNamed(self::BENEFITS_ACCOUNT_NAME);

            $profit = max(0, array_sum($depart->ticketSalesAmountByBusId()) - $lockedCostReport->totalRentalFees());

            $monthlyPlannedCosts = (int) MonthlyExpenseBudget::query()
                ->where('month', $depart->date->copy()->startOfMonth()->toDateString())
                ->sum('amount');

            $chargesStillToFill = max(0, $monthlyPlannedCosts - $this->chargesAllocatedByOtherReportsOfSameMonth($lockedCostReport, $chargesAccount));

            $targetCharges = min($profit, $chargesStillToFill);
            $targetBenefits = $profit - $targetCharges;

            $adjustments = [
                [$chargesAccount, $targetCharges - $this->netAllocatedToAccount($chargesAccount, [$lockedCostReport->id])],
                [$benefitsAccount, $targetBenefits - $this->netAllocatedToAccount($benefitsAccount, [$lockedCostReport->id])],
            ];
            // Giving money back first, so what is returned is available again before anything is taken.
            usort($adjustments, fn (array $first, array $second): int => $first[1] <=> $second[1]);

            foreach ($adjustments as [$destinationAccount, $difference]) {
                if ($difference === 0) {
                    continue;
                }

                $label = ($difference > 0 ? 'Part du bénéfice du départ ' : 'Retour de la part du bénéfice du départ ')
                    .$depart->identifier(with_trajet_prefix: true);

                [$fromAccount, $toAccount] = $difference > 0
                    ? [$ticketSalesAccount, $destinationAccount]
                    : [$destinationAccount, $ticketSalesAccount];

                $this->accountService->transferBetweenAccounts(
                    $fromAccount,
                    $toAccount,
                    abs($difference),
                    $label,
                    self::REFERENCE_TYPE,
                    $lockedCostReport->id,
                    $userId,
                );
            }

            return ['charges' => $targetCharges, 'benefits' => $targetBenefits];
        });
    }

    private function lockedAccountNamed(string $accountName): Account
    {
        $account = Account::query()->where('name', $accountName)->lockForUpdate()->first();

        if ($account === null) {
            throw new RuntimeException("Le compte «{$accountName}» est introuvable : lancez les migrations.");
        }

        return $account;
    }

    private function chargesAllocatedByOtherReportsOfSameMonth(DepartCostReport $departCostReport, Account $chargesAccount): int
    {
        $otherReportIdsOfSameMonth = DepartCostReport::query()
            ->whereKeyNot($departCostReport->id)
            ->whereHas('depart', fn ($departQuery) => $departQuery->whereBetween('date', [
                $departCostReport->depart->date->copy()->startOfMonth(),
                $departCostReport->depart->date->copy()->endOfMonth(),
            ]))
            ->pluck('id')
            ->all();

        return $this->netAllocatedToAccount($chargesAccount, $otherReportIdsOfSameMonth);
    }

    /**
     * What the given cost reports hold in an account: allocations received minus allocations given back.
     *
     * @param  array<int, int>  $costReportIds
     */
    private function netAllocatedToAccount(Account $account, array $costReportIds): int
    {
        if ($costReportIds === []) {
            return 0;
        }

        return (int) AccountTransaction::query()
            ->where('account_id', $account->id)
            ->where('reference_type', self::REFERENCE_TYPE)
            ->whereIn('reference_id', $costReportIds)
            ->sum(DB::raw("CASE WHEN transaction_type = '".AccountTransactionType::Credit->value."' THEN amount ELSE -amount END"));
    }
}
