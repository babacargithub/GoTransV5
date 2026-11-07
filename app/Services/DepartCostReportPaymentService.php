<?php

namespace App\Services;

use App\Enums\AccountTransactionCategory;
use App\Models\DepartCostReport;
use App\Models\DepartCostReportBusRental;
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
}
