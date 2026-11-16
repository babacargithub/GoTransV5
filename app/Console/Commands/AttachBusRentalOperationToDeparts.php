<?php

namespace App\Console\Commands;

use App\Enums\AccountTransactionCategory;
use App\Enums\AccountTransactionType;
use App\Enums\CaisseTransactionType;
use App\Enums\ExpenseTypeCode;
use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Caisse;
use App\Models\CaisseTransaction;
use App\Models\DepartCostReport;
use App\Models\ExpenseType;
use App\Services\AccountService;
use App\Services\DepartCostReportPaymentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One-time data fix: a single bulk "sortie de caisse" saved by hand to pay the bus rentals of several
 * départs (e.g. "Location de bus du samedi 31 oct au lundi 2 nov") is split into one operation per
 * départ, exactly as the "Enregistrer en caisse" bulk payment of the Bilans page records them. What the
 * départs do not account for stays on the original operation, relabelled.
 *
 * For each départ, in a single transaction that rolls back on any inconsistency:
 * - not paid yet and without operation: a caisse withdrawal + account debit pair is created on the same
 *   caisse and account as the original operation, dated like it, and the rentals are marked paid;
 * - already paid by exactly one DEPART_COST_REPORT operation of the full fee (e.g. recorded earlier through
 *   the bulk payment): that operation is reused and only redated;
 * - anything else aborts the whole run.
 * The original operation is then reduced by what the départs account for, and optionally redated in the
 * accounts (not in the caisse) so the part belonging to an earlier month lands in that month's reports. Nothing is deleted, so total
 * money out is unchanged, and SUM(accounts) == SUM(caisses) is asserted before committing.
 *
 * Dry run by default: everything is executed, printed, then rolled back unless --apply is given.
 */
class AttachBusRentalOperationToDeparts extends Command
{
    protected $signature = 'ledger:attach-bus-rental-operation
        {accountTransactionId : Id of the original account transaction (SORTIE_DE_CAISSE debit) to split}
        {--depart=* : Id of a départ covered by that operation (repeat the option for each départ)}
        {--residual-label= : New label of the original operation, which keeps the part not attached to any départ}
        {--residual-date= : Date (Y-m-d) the part not attached to any départ is booked on in the accounts (the caisse withdrawal keeps its real date)}
        {--apply : Persist the changes instead of only simulating them}';

    protected $description = 'Split a legacy bulk bus rental sortie de caisse into one operation per départ (one-time data fix)';

    public function handle(AccountService $accountService): int
    {
        $departIds = array_map('intval', (array) $this->option('depart'));
        $residualLabel = trim((string) $this->option('residual-label'));
        $residualDate = trim((string) $this->option('residual-date'));
        $shouldApply = (bool) $this->option('apply');

        if ($departIds === [] || count($departIds) !== count(array_unique($departIds))) {
            $this->error('Indiquez au moins un départ, sans doublon (--depart=ID).');

            return self::FAILURE;
        }

        if ($residualLabel === '') {
            $this->error('Indiquez le nouveau libellé de l\'opération d\'origine (--residual-label=...).');

            return self::FAILURE;
        }

        if ($residualDate !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $residualDate)) {
            $this->error('La date doit être au format AAAA-MM-JJ (--residual-date=2026-10-31).');

            return self::FAILURE;
        }

        DB::beginTransaction();

        try {
            $this->splitOperation((int) $this->argument('accountTransactionId'), $departIds, $residualLabel, $residualDate !== '' ? $residualDate : null, $accountService);
        } catch (\Throwable $exception) {
            DB::rollBack();
            $this->error('Opération annulée, rien n\'a été modifié : '.$exception->getMessage());

            return self::FAILURE;
        }

        if (! $shouldApply) {
            DB::rollBack();
            $this->warn('Simulation terminée : rien n\'a été enregistré. Relancez avec --apply pour appliquer.');

            return self::SUCCESS;
        }

        DB::commit();
        $this->info('Correction appliquée.');

        return self::SUCCESS;
    }

    /**
     * @param  array<int, int>  $departIds
     */
    private function splitOperation(int $accountTransactionId, array $departIds, string $residualLabel, ?string $residualDate, AccountService $accountService): void
    {
        $originalAccountTransaction = AccountTransaction::whereKey($accountTransactionId)->lockForUpdate()->firstOrFail();

        if (
            $originalAccountTransaction->reference_type !== 'SORTIE_DE_CAISSE'
            || $originalAccountTransaction->reference_id !== null
            || $originalAccountTransaction->transaction_type !== AccountTransactionType::Debit
            || $originalAccountTransaction->category !== AccountTransactionCategory::Expense
        ) {
            throw new RuntimeException("La transaction {$accountTransactionId} n'est pas une sortie de caisse manuelle (charge, non rattachée).");
        }

        $originalCaisseTransaction = $this->singleCaisseWithdrawalMatching($originalAccountTransaction);
        $account = Account::whereKey($originalAccountTransaction->account_id)->lockForUpdate()->firstOrFail();
        $originalCaisse = Caisse::whereKey($originalCaisseTransaction->caisse_id)->lockForUpdate()->firstOrFail();
        $operationDate = $originalAccountTransaction->created_at;
        $locationDeBusTypeId = ExpenseType::idForCode(ExpenseTypeCode::LocationDeBus);

        $touchedCaisseIds = [$originalCaisse->id];
        $amountAttachedToDeparts = 0;

        foreach ($departIds as $departId) {
            $costReport = $this->singleLockedCostReport($departId);
            $rentalFees = (int) $costReport->busRentals->sum('rental_fee');
            $paidAmount = (int) $costReport->busRentals->sum('paid_amount');
            $existingOperations = AccountTransaction::query()
                ->where('reference_type', DepartCostReportPaymentService::ACCOUNT_REFERENCE_TYPE)
                ->where('reference_id', $costReport->id)
                ->lockForUpdate()
                ->get();
            $departLabel = DepartCostReportPaymentService::labelForDepart($costReport->depart);

            if ($rentalFees <= 0) {
                throw new RuntimeException("Le départ {$departId} n'a aucune location de bus à payer.");
            }

            if ($paidAmount === 0 && $existingOperations->isEmpty()) {
                $this->createOperationPair($account, $originalCaisse, $originalAccountTransaction, $costReport, $rentalFees, $departLabel, $locationDeBusTypeId);
                $costReport->busRentals->each(fn ($rental) => $rental->update(['paid_amount' => $rental->rental_fee]));
                $this->line("  + {$departLabel} : {$rentalFees} F (créée)");
            } elseif ($paidAmount === $rentalFees && $existingOperations->count() === 1 && $existingOperations->first()->amount === $rentalFees) {
                $existingCaisseTransaction = $this->singleCaisseWithdrawalMatching($existingOperations->first());
                $this->redate($existingOperations->first(), $operationDate);
                $this->redate($existingCaisseTransaction, $operationDate);
                $touchedCaisseIds[] = $existingCaisseTransaction->caisse_id;
                $this->line("  ~ {$departLabel} : {$rentalFees} F (opération existante redatée)");
            } else {
                throw new RuntimeException("Le départ {$departId} est dans un état incohérent (payé {$paidAmount} F sur {$rentalFees} F, {$existingOperations->count()} opération(s)) : correction manuelle nécessaire.");
            }

            $amountAttachedToDeparts += $rentalFees;
        }

        $residualAmount = $originalAccountTransaction->amount - $amountAttachedToDeparts;

        if ($residualAmount <= 0) {
            throw new RuntimeException("Les départs ({$amountAttachedToDeparts} F) dépassent ou égalent l'opération d'origine ({$originalAccountTransaction->amount} F).");
        }

        $this->line("  = Opération d'origine #{$accountTransactionId} : {$originalAccountTransaction->amount} F → {$residualAmount} F « {$residualLabel} »");
        $originalAccountTransaction->update(['amount' => $residualAmount, 'label' => $residualLabel]);
        $originalCaisseTransaction->update(['amount' => $residualAmount, 'label' => $residualLabel]);

        if ($residualDate !== null) {
            $this->line("  = Partie non rattachée imputée au {$residualDate} dans les comptes (la caisse garde sa date réelle)");
            $originalAccountTransaction->forceFill(['created_at' => $residualDate.' 23:59:59'])->save();
        }

        $this->recomputeBalances($account, array_unique($touchedCaisseIds), $accountService);
    }

    private function singleCaisseWithdrawalMatching(AccountTransaction $accountTransaction): CaisseTransaction
    {
        $matchingCaisseTransactions = CaisseTransaction::query()
            ->where('transaction_type', CaisseTransactionType::Withdraw->value)
            ->where('label', $accountTransaction->label)
            ->where('amount', $accountTransaction->amount)
            ->where('created_at', $accountTransaction->created_at)
            ->lockForUpdate()
            ->get();

        if ($matchingCaisseTransactions->count() !== 1) {
            throw new RuntimeException("Impossible d'identifier de façon unique la sortie de caisse de la transaction {$accountTransaction->id} ({$matchingCaisseTransactions->count()} correspondances).");
        }

        return $matchingCaisseTransactions->first();
    }

    private function singleLockedCostReport(int $departId): DepartCostReport
    {
        $costReports = DepartCostReport::query()
            ->where('depart_id', $departId)
            ->lockForUpdate()
            ->with(['busRentals', 'depart'])
            ->get();

        if ($costReports->count() !== 1) {
            throw new RuntimeException("Le départ {$departId} doit avoir exactement un bilan ({$costReports->count()} trouvé(s)).");
        }

        return $costReports->first();
    }

    private function createOperationPair(
        Account $account,
        Caisse $caisse,
        AccountTransaction $originalAccountTransaction,
        DepartCostReport $costReport,
        int $amount,
        string $label,
        ?int $expenseTypeId,
    ): void {
        $operationDate = $originalAccountTransaction->created_at;

        (new AccountTransaction)->forceFill([
            'account_id' => $account->id,
            'user_id' => $originalAccountTransaction->user_id,
            'amount' => $amount,
            'transaction_type' => AccountTransactionType::Debit,
            'category' => AccountTransactionCategory::Expense,
            'expense_type_id' => $expenseTypeId,
            'label' => $label,
            'reference_type' => DepartCostReportPaymentService::ACCOUNT_REFERENCE_TYPE,
            'reference_id' => $costReport->id,
            'created_at' => $operationDate,
            'updated_at' => $operationDate,
        ])->save();

        (new CaisseTransaction)->forceFill([
            'caisse_id' => $caisse->id,
            'user_id' => $originalAccountTransaction->user_id,
            'amount' => $amount,
            'label' => $label,
            'transaction_type' => CaisseTransactionType::Withdraw,
            'created_at' => $operationDate,
            'updated_at' => $operationDate,
        ])->save();
    }

    private function redate(AccountTransaction|CaisseTransaction $transaction, \DateTimeInterface $operationDate): void
    {
        $transaction->forceFill(['created_at' => $operationDate, 'updated_at' => $operationDate])->save();
    }

    /**
     * @param  array<int, int>  $caisseIds
     */
    private function recomputeBalances(Account $account, array $caisseIds, AccountService $accountService): void
    {
        $this->line("  Compte « {$account->name} » : {$account->balance} F → ".$account->updateBalanceFromLedger()->balance.' F');

        foreach (Caisse::whereIn('id', $caisseIds)->get() as $caisse) {
            $previousBalance = $caisse->balance;
            $this->line("  Caisse « {$caisse->name} » : {$previousBalance} F → ".$caisse->updateBalanceFromLedger()->balance.' F');
        }

        $accountService->assertGlobalInvariantHolds();
    }
}
