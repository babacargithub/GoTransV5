<?php

namespace App\Services;

use App\Enums\AccountTransactionCategory;
use App\Enums\ExpenseTypeCode;
use App\Models\Depart;
use App\Models\DepartCostReport;
use App\Models\DepartCostReportBusRental;
use App\Models\ExpenseType;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Records, in the caisses and accounts, the bus rental fees actually paid out for a départ bilan.
 *
 * Money-out: the bilan and its rentals are re-read under a row lock inside the transaction, every
 * amount is re-validated, and any violation throws so nothing is persisted. Each bus rental tracks
 * how much was already paid out (paid_amount) and can never be paid beyond its rental fee, which
 * makes a double submission or a retry unable to pay a bus twice.
 */
class DepartCostReportPaymentService
{
    public const ACCOUNT_REFERENCE_TYPE = 'DEPART_COST_REPORT';

    public function __construct(private readonly AccountService $accountService) {}

    /**
     * @param  array<int, int>  $paidAmountByBusId  bus id => amount paid to that bus' owner now
     * @param  array<int, int>  $amountByCaisseId  caisse id => amount withdrawn
     * @param  array<int, int>  $amountByAccountId  account id => amount debited
     *
     * @throws InvalidArgumentException when any amount or total is invalid
     */
    public function recordBusRentalPayments(
        DepartCostReport $departCostReport,
        array $paidAmountByBusId,
        array $amountByCaisseId,
        array $amountByAccountId,
        string $label,
        ?int $userId = null,
    ): void {
        $paidAmountByBusId = array_filter($paidAmountByBusId, fn (int $amount): bool => $amount !== 0);

        if ($paidAmountByBusId === []) {
            throw new InvalidArgumentException('Indiquez le montant payé pour au moins un bus.');
        }

        if (trim($label) === '') {
            throw new InvalidArgumentException('Le libellé est obligatoire.');
        }

        DB::transaction(function () use ($departCostReport, $paidAmountByBusId, $amountByCaisseId, $amountByAccountId, $label, $userId): void {
            DepartCostReport::whereKey($departCostReport->id)->lockForUpdate()->firstOrFail();

            $rentalsByBusId = DepartCostReportBusRental::query()
                ->where('depart_cost_report_id', $departCostReport->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('bus_id');

            foreach ($paidAmountByBusId as $busId => $paidAmount) {
                $rental = $rentalsByBusId->get($busId);

                if ($rental === null) {
                    throw new InvalidArgumentException("Le bus {$busId} ne fait pas partie de ce bilan.");
                }

                if ($paidAmount < 0 || $paidAmount > $rental->remainingAmountToPay()) {
                    throw new InvalidArgumentException(
                        "Montant invalide pour le bus «{$rental->bus?->name}» : "
                        ."{$paidAmount} F demandés, reste à payer {$rental->remainingAmountToPay()} F."
                    );
                }
            }

            $this->accountService->processMultiSourceSortieDeCaisse(
                $amountByCaisseId,
                $amountByAccountId,
                $label,
                self::ACCOUNT_REFERENCE_TYPE,
                $departCostReport->id,
                $userId,
                AccountTransactionCategory::Expense,
                ExpenseType::idForCode(ExpenseTypeCode::LocationDeBus),
            );

            foreach ($paidAmountByBusId as $busId => $paidAmount) {
                $rentalsByBusId->get($busId)->increment('paid_amount', $paidAmount);
            }

            $overpaidRentalsCount = DepartCostReportBusRental::where('depart_cost_report_id', $departCostReport->id)
                ->whereColumn('paid_amount', '>', 'rental_fee')
                ->count();

            if ($overpaidRentalsCount > 0) {
                throw new InvalidArgumentException('Incohérence détectée sur les montants payés : opération annulée.');
            }
        });
    }

    /**
     * Pays, in one all-or-nothing transaction, everything still owed on the bus rentals of several départs.
     *
     * Each départ is recorded as its own caisse/account operation (own label, own reference), exactly as
     * if it had been paid alone. The caisse and account amounts typed once for the whole batch are
     * dispatched across the départs in order, so the batch totals must equal the sum owed.
     *
     * @param  array<int, int>  $departIds
     * @param  array<int, int>  $amountByCaisseId  caisse id => amount withdrawn for the whole batch
     * @param  array<int, int>  $amountByAccountId  account id => amount debited for the whole batch
     * @return array<int, int> amount paid, keyed by départ id
     *
     * @throws InvalidArgumentException when a départ has no (or several) cost reports, owes nothing, or totals differ
     */
    public function recordBulkBusRentalPayments(
        array $departIds,
        array $amountByCaisseId,
        array $amountByAccountId,
        ?int $userId = null,
    ): array {
        if ($departIds === []) {
            throw new InvalidArgumentException('Sélectionnez au moins un départ.');
        }

        if (count($departIds) !== count(array_unique($departIds))) {
            throw new InvalidArgumentException('Un départ ne peut être sélectionné qu\'une seule fois.');
        }

        return DB::transaction(function () use ($departIds, $amountByCaisseId, $amountByAccountId, $userId): array {
            $costReportsByDepartId = $this->lockedCostReportsByDepartId($departIds);

            $amountOwedByDepartId = [];
            $paidAmountByBusIdByDepartId = [];

            foreach ($departIds as $departId) {
                $departCostReport = $costReportsByDepartId[$departId];

                $paidAmountByBusId = $departCostReport->busRentals
                    ->mapWithKeys(fn (DepartCostReportBusRental $rental): array => [$rental->bus_id => $rental->remainingAmountToPay()])
                    ->filter(fn (int $remainingAmount): bool => $remainingAmount > 0)
                    ->all();

                if ($paidAmountByBusId === []) {
                    throw new InvalidArgumentException(
                        "Le départ «{$departCostReport->depart->identifier(with_trajet_prefix: true)}» n'a plus rien à payer."
                    );
                }

                $paidAmountByBusIdByDepartId[$departId] = $paidAmountByBusId;
                $amountOwedByDepartId[$departId] = array_sum($paidAmountByBusId);
            }

            $totalOwed = array_sum($amountOwedByDepartId);

            if (array_sum($amountByCaisseId) !== $totalOwed) {
                throw new InvalidArgumentException('Le total des caisses ('.array_sum($amountByCaisseId)." F) doit être égal au total à payer ({$totalOwed} F).");
            }

            if (array_sum($amountByAccountId) !== $totalOwed) {
                throw new InvalidArgumentException('Le total des comptes ('.array_sum($amountByAccountId)." F) doit être égal au total à payer ({$totalOwed} F).");
            }

            $caisseSharesByDepartId = $this->dispatchAmountsAcrossDeparts($amountByCaisseId, $amountOwedByDepartId);
            $accountSharesByDepartId = $this->dispatchAmountsAcrossDeparts($amountByAccountId, $amountOwedByDepartId);

            foreach ($departIds as $departId) {
                $departCostReport = $costReportsByDepartId[$departId];

                $this->recordBusRentalPayments(
                    $departCostReport,
                    $paidAmountByBusIdByDepartId[$departId],
                    $caisseSharesByDepartId[$departId],
                    $accountSharesByDepartId[$departId],
                    self::labelForDepart($departCostReport->depart),
                    $userId,
                );
            }

            return $amountOwedByDepartId;
        });
    }

    public static function labelForDepart(Depart $depart): string
    {
        return 'Location de bus '.$depart->identifier(with_trajet_prefix: true);
    }

    /**
     * Locks and returns the single cost report of every given départ, with its bus rentals.
     * Refuses a départ without a cost report, or with more than one (which the schema forbids, but money
     * must never be paid on an inconsistent state).
     *
     * @param  array<int, int>  $departIds
     * @return array<int, DepartCostReport> keyed by départ id
     */
    private function lockedCostReportsByDepartId(array $departIds): array
    {
        $costReports = DepartCostReport::query()
            ->whereIn('depart_id', $departIds)
            ->lockForUpdate()
            ->with(['busRentals', 'depart'])
            ->get()
            ->groupBy('depart_id');

        $costReportsByDepartId = [];

        foreach ($departIds as $departId) {
            $costReportsOfDepart = $costReports->get($departId);

            if ($costReportsOfDepart === null) {
                throw new InvalidArgumentException("Le départ {$departId} n'a pas de bilan enregistré.");
            }

            if ($costReportsOfDepart->count() !== 1) {
                throw new InvalidArgumentException("Le départ {$departId} a plusieurs bilans : opération annulée.");
            }

            $costReportsByDepartId[$departId] = $costReportsOfDepart->first();
        }

        return $costReportsByDepartId;
    }

    /**
     * Splits each source amount (caisse or account) across the départs in order, filling the first départ
     * before moving to the next, so every départ receives exactly what it owes.
     *
     * @param  array<int, int>  $amountBySourceId
     * @param  array<int, int>  $amountOwedByDepartId
     * @return array<int, array<int, int>> départ id => (source id => amount)
     */
    private function dispatchAmountsAcrossDeparts(array $amountBySourceId, array $amountOwedByDepartId): array
    {
        $sharesByDepartId = [];
        $remainingBySourceId = $amountBySourceId;

        foreach ($amountOwedByDepartId as $departId => $amountOwed) {
            $sharesByDepartId[$departId] = [];

            foreach ($remainingBySourceId as $sourceId => $remainingOfSource) {
                if ($amountOwed === 0) {
                    break;
                }

                $share = min($amountOwed, $remainingOfSource);

                if ($share <= 0) {
                    continue;
                }

                $sharesByDepartId[$departId][$sourceId] = $share;
                $remainingBySourceId[$sourceId] -= $share;
                $amountOwed -= $share;
            }
        }

        return $sharesByDepartId;
    }
}
