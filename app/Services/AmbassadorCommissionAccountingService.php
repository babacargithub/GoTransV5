<?php

namespace App\Services;

use App\Enums\AccountTransactionCategory;
use App\Enums\AmbassadorEarningStatus;
use App\Enums\AmbassadorPayoutStatus;
use App\Enums\CaisseCode;
use App\Exceptions\InsufficientAccountBalanceException;
use App\Models\AccountTransaction;
use App\Models\AmbassadorEarning;
use App\Models\AmbassadorPayout;
use App\Models\Caisse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Keeps the "Commissions ambassadeurs" account in step with the earnings ledger:
 *  - reserve: as soon as a commission is earned, its amount moves from TicketSales to the commissions account
 *    (an internal transfer, so it is neither revenue nor expense and no caisse changes);
 *  - release: a cancelled commission goes back to TicketSales;
 *  - payout: when the manager's payout is settled, the amount leaves the Wave caisse and is debited from the
 *    commissions account as an expense (the only moment the commission counts against ProfitService's profit).
 *
 * Every method is idempotent (looked up by reference type and earning/payout id), so queued jobs can be retried
 * and the backfill command can be re-run safely.
 */
class AmbassadorCommissionAccountingService
{
    public const string RESERVE_REFERENCE_TYPE = 'AMBASSADOR_COMMISSION_RESERVE';

    public const string RELEASE_REFERENCE_TYPE = 'AMBASSADOR_COMMISSION_RELEASE';

    public const string PAYOUT_REFERENCE_TYPE = 'AMBASSADOR_PAYOUT';

    public function __construct(private readonly AccountService $accountService) {}

    /**
     * Sets the commission aside. Does nothing for a cancelled commission or one already reserved.
     *
     * @return bool whether a transfer was recorded now
     *
     * @throws InsufficientAccountBalanceException when TicketSales no longer holds enough to cover it
     */
    public function reserveEarning(AmbassadorEarning $earning): bool
    {
        return DB::transaction(function () use ($earning): bool {
            $lockedEarning = AmbassadorEarning::query()->lockForUpdate()->findOrFail($earning->id);

            if ($lockedEarning->status === AmbassadorEarningStatus::Cancelled
                || $this->hasTransaction(self::RESERVE_REFERENCE_TYPE, $lockedEarning->id)) {
                return false;
            }

            $this->moveBetweenTicketSalesAndCommissions(
                amount: $lockedEarning->amount,
                label: "Commission ambassadeur réservée : {$lockedEarning->label}",
                referenceType: self::RESERVE_REFERENCE_TYPE,
                referenceId: $lockedEarning->id,
                towardsCommissions: true,
            );

            return true;
        });
    }

    /**
     * Gives a cancelled commission back to TicketSales. Does nothing when it was never reserved or already
     * released, or when it is not cancelled.
     *
     * @return bool whether a transfer was recorded now
     */
    public function releaseEarning(AmbassadorEarning $earning): bool
    {
        return DB::transaction(function () use ($earning): bool {
            $lockedEarning = AmbassadorEarning::query()->lockForUpdate()->findOrFail($earning->id);

            if ($lockedEarning->status !== AmbassadorEarningStatus::Cancelled
                || ! $this->hasTransaction(self::RESERVE_REFERENCE_TYPE, $lockedEarning->id)
                || $this->hasTransaction(self::RELEASE_REFERENCE_TYPE, $lockedEarning->id)) {
                return false;
            }

            $this->moveBetweenTicketSalesAndCommissions(
                amount: $lockedEarning->amount,
                label: "Commission ambassadeur annulée : {$lockedEarning->label}",
                referenceType: self::RELEASE_REFERENCE_TYPE,
                referenceId: $lockedEarning->id,
                towardsCommissions: false,
            );

            return true;
        });
    }

    /**
     * Records the cash-out of a settled payout: Wave caisse withdrawal and commissions account debit, as an
     * expense. The commissions it pays must already be reserved: nothing else is touched here, so a missing
     * reserve fails the whole recording (to be retried) instead of leaving it half done. Does nothing for an
     * unsettled payout or one already recorded.
     *
     * @return bool whether the cash-out was recorded now
     *
     * @throws RuntimeException when the Wave caisse is not configured or the payout amount differs from its earnings
     * @throws InsufficientAccountBalanceException when the commissions are not all reserved yet
     */
    public function recordPayout(AmbassadorPayout $payout): bool
    {
        return DB::transaction(function () use ($payout): bool {
            $lockedPayout = AmbassadorPayout::query()->lockForUpdate()->findOrFail($payout->id);

            if ($lockedPayout->status !== AmbassadorPayoutStatus::Success
                || $this->hasTransaction(self::PAYOUT_REFERENCE_TYPE, $lockedPayout->id)) {
                return false;
            }

            $waveCaisse = Caisse::findByCode(CaisseCode::Wave)
                ?? throw new RuntimeException('The Wave caisse is not configured: ambassador payout '.$lockedPayout->id.' was not recorded.');

            $paidEarningsTotal = (int) $lockedPayout->earnings->sum('amount');

            if ($paidEarningsTotal !== $lockedPayout->amount) {
                throw new RuntimeException("Ambassador payout {$lockedPayout->id} amount ({$lockedPayout->amount}) differs from the total of its earnings ({$paidEarningsTotal}): it was not recorded.");
            }

            $label = "Paiement ambassadeur {$lockedPayout->ambassador->full_name} (Wave)";

            $this->accountService->withdrawFromCaisseAndDebitAccount(
                caisse: $waveCaisse,
                account: $this->accountService->getOrCreateAmbassadorCommissionsAccount(),
                amount: $lockedPayout->amount,
                caisseLabel: $label,
                accountLabel: $label,
                referenceType: self::PAYOUT_REFERENCE_TYPE,
                referenceId: $lockedPayout->id,
                categoryOverride: AccountTransactionCategory::Expense,
            );

            return true;
        });
    }

    /**
     * Brings the ledgers up to date for commissions and payouts that predate this accounting (or whose queued
     * job failed). A problem on one line is logged and counted, never stops the others.
     *
     * @return array{reserved: int, released: int, payouts_recorded: int, failed: int}
     */
    public function recordMissingEntries(): array
    {
        $outcome = ['reserved' => 0, 'released' => 0, 'payouts_recorded' => 0, 'failed' => 0];

        $this->whereCommissionsTransactionExists(AmbassadorEarning::query()->notCancelled(), self::RESERVE_REFERENCE_TYPE, shouldExist: false)
            ->lazyById()
            ->each(function (AmbassadorEarning $earning) use (&$outcome): void {
                $this->runCounting(fn (): bool => $this->reserveEarning($earning), 'reserved', $outcome, "earning {$earning->id}");
            });

        $cancelledEarningsQuery = AmbassadorEarning::query()->where('status', AmbassadorEarningStatus::Cancelled);
        $this->whereCommissionsTransactionExists($cancelledEarningsQuery, self::RESERVE_REFERENCE_TYPE, shouldExist: true);
        $this->whereCommissionsTransactionExists($cancelledEarningsQuery, self::RELEASE_REFERENCE_TYPE, shouldExist: false)
            ->lazyById()
            ->each(function (AmbassadorEarning $earning) use (&$outcome): void {
                $this->runCounting(fn (): bool => $this->releaseEarning($earning), 'released', $outcome, "cancelled earning {$earning->id}");
            });

        $this->whereCommissionsTransactionExists(AmbassadorPayout::query()->where('status', AmbassadorPayoutStatus::Success), self::PAYOUT_REFERENCE_TYPE, shouldExist: false)
            ->lazyById()
            ->each(function (AmbassadorPayout $payout) use (&$outcome): void {
                $this->runCounting(fn (): bool => $this->recordPayout($payout), 'payouts_recorded', $outcome, "payout {$payout->id}");
            });

        return $outcome;
    }

    /**
     * @param  callable(): bool  $operation
     * @param  array{reserved: int, released: int, payouts_recorded: int, failed: int}  $outcome
     */
    private function runCounting(callable $operation, string $successKey, array &$outcome, string $subjectLabel): void
    {
        try {
            if ($operation()) {
                $outcome[$successKey]++;
            }
        } catch (\Throwable $throwable) {
            $outcome['failed']++;
            Log::error("Ambassador commission accounting failed for {$subjectLabel}: {$throwable->getMessage()}");
        }
    }

    /**
     * Scoped to the commissions account, so a transaction of another module that happens to share a reference
     * type and id never counts as ours.
     */
    private function hasTransaction(string $referenceType, int $referenceId): bool
    {
        return AccountTransaction::query()
            ->where('account_id', $this->accountService->getOrCreateAmbassadorCommissionsAccount()->id)
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->exists();
    }

    /**
     * Keeps the rows of $query that have (or, with $shouldExist false, have not) a commissions account
     * transaction of the given reference type, so the backfill only walks lines that still need work.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private function whereCommissionsTransactionExists(Builder $query, string $referenceType, bool $shouldExist): Builder
    {
        $commissionsAccountId = $this->accountService->getOrCreateAmbassadorCommissionsAccount()->id;

        $existingTransactionQuery = fn ($transactionQuery) => $transactionQuery
            ->select(DB::raw(1))
            ->from('account_transactions')
            ->where('account_transactions.account_id', $commissionsAccountId)
            ->where('account_transactions.reference_type', $referenceType)
            ->whereColumn('account_transactions.reference_id', $query->getModel()->getQualifiedKeyName());

        return $shouldExist
            ? $query->whereExists($existingTransactionQuery)
            : $query->whereNotExists($existingTransactionQuery);
    }

    /**
     * Both legs are explicitly Internal: a reservation or a release is a reallocation, never revenue or expense.
     */
    private function moveBetweenTicketSalesAndCommissions(int $amount, string $label, string $referenceType, int $referenceId, bool $towardsCommissions): void
    {
        $ticketSalesAccount = $this->accountService->getOrCreateTicketSalesAccount();
        $commissionsAccount = $this->accountService->getOrCreateAmbassadorCommissionsAccount();

        [$sourceAccount, $destinationAccount] = $towardsCommissions
            ? [$ticketSalesAccount, $commissionsAccount]
            : [$commissionsAccount, $ticketSalesAccount];

        $this->accountService->debit($sourceAccount, $amount, $label, $referenceType, $referenceId, null, AccountTransactionCategory::Internal);
        $this->accountService->credit($destinationAccount, $amount, $label, $referenceType, $referenceId, null, AccountTransactionCategory::Internal);
    }
}
