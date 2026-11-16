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
use App\Models\Depart;
use App\Models\DepartCostReport;
use App\Models\ExpenseType;
use App\Services\AccountService;
use App\Services\DepartCostReportPaymentService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One-time data fix for départs that took place before the départ cost reports existed: their bus rentals
 * were paid through free-text "Location de bus" sorties de caisse. A mapping file says which operation paid
 * how much of which départ; this command then creates the cost reports (bus owner "Annonyme", to be edited
 * later) and splits each operation into one caisse withdrawal + account debit pair per départ, exactly as
 * the bulk "Enregistrer en caisse" payment records them.
 *
 * Mapping file (JSON): {"allocations": [{"operationId": 722, "departId": 4265, "amount": 63391}, ...]}
 * operationId is the account transaction (manual SORTIE_DE_CAISSE debit) that paid; amount is the part of
 * it that paid that départ's bus. A départ paid by several operations sums them; an operation can pay
 * several départs. Whatever an operation pays beyond its allocations stays on it as an unattached expense.
 *
 * Everything runs in one transaction that rolls back on any inconsistency, and SUM(accounts) ==
 * SUM(caisses) is asserted before committing. Nothing is deleted and dates are kept, so total money out
 * and the month each expense falls in are unchanged. Dry run by default; --apply persists.
 */
class CreateCostReportsFromBusRentalOperations extends Command
{
    private const BUS_OWNER_PLACEHOLDER_NAME = 'Annonyme';

    protected $signature = 'ledger:create-bus-rental-cost-reports
        {mappingFile : Path to the JSON mapping of operations to départs}
        {--apply : Persist the changes instead of only simulating them}';

    protected $description = 'Create cost reports for past départs and split their bus rental sorties de caisse per départ (one-time data fix)';

    /**
     * @var array<int, int> caisse transaction ids already claimed by an operation of this run
     */
    private array $claimedCaisseTransactionIds = [];

    public function handle(AccountService $accountService): int
    {
        try {
            $allocationsByOperationId = $this->readAllocationsByOperationId((string) $this->argument('mappingFile'));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        DB::beginTransaction();

        try {
            $this->claimedCaisseTransactionIds = [];
            $this->createReportsAndSplitOperations($allocationsByOperationId, $accountService);
        } catch (\Throwable $exception) {
            DB::rollBack();
            $this->error('Opération annulée, rien n\'a été modifié : '.$exception->getMessage());

            return self::FAILURE;
        }

        if (! $this->option('apply')) {
            DB::rollBack();
            $this->warn('Simulation terminée : rien n\'a été enregistré. Relancez avec --apply pour appliquer.');

            return self::SUCCESS;
        }

        DB::commit();
        $this->info('Correction appliquée.');

        return self::SUCCESS;
    }

    /**
     * @return array<int, array<int, array{departId: int, amount: int}>> allocations grouped by operation id
     */
    private function readAllocationsByOperationId(string $mappingFile): array
    {
        if (! is_file($mappingFile)) {
            throw new RuntimeException("Fichier de correspondance introuvable : {$mappingFile}");
        }

        $allocations = json_decode((string) file_get_contents($mappingFile), true)['allocations'] ?? null;

        if (! is_array($allocations) || $allocations === []) {
            throw new RuntimeException('Le fichier doit contenir une liste « allocations » non vide.');
        }

        $allocationsByOperationId = [];

        foreach ($allocations as $position => $allocation) {
            $operationId = $allocation['operationId'] ?? null;
            $departId = $allocation['departId'] ?? null;
            $amount = $allocation['amount'] ?? null;

            if (! is_int($operationId) || ! is_int($departId) || ! is_int($amount) || $amount <= 0) {
                throw new RuntimeException('Allocation #'.($position + 1).' invalide : operationId, departId et amount (entier > 0) sont requis.');
            }

            $allocationsByOperationId[$operationId][] = ['departId' => $departId, 'amount' => $amount];
        }

        return $allocationsByOperationId;
    }

    /**
     * @param  array<int, array<int, array{departId: int, amount: int}>>  $allocationsByOperationId
     */
    private function createReportsAndSplitOperations(array $allocationsByOperationId, AccountService $accountService): void
    {
        $operations = AccountTransaction::query()
            ->whereIn('id', array_keys($allocationsByOperationId))
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach (array_keys($allocationsByOperationId) as $operationId) {
            $this->assertOperationCanBeSplit($operations->get($operationId), $operationId);
        }

        $feeByDepartId = [];
        $earliestOperationDateByDepartId = [];
        $originalLabelsByDepartId = [];

        foreach ($allocationsByOperationId as $operationId => $allocations) {
            foreach ($allocations as $allocation) {
                $feeByDepartId[$allocation['departId']] = ($feeByDepartId[$allocation['departId']] ?? 0) + $allocation['amount'];
                $operationDate = $operations[$operationId]->created_at;
                $originalLabelsByDepartId[$allocation['departId']][] = trim((string) $operations[$operationId]->label);
                $earliestOperationDateByDepartId[$allocation['departId']] = min($earliestOperationDateByDepartId[$allocation['departId']] ?? $operationDate, $operationDate);
            }
        }

        $costReportsByDepartId = $this->createCostReports($feeByDepartId, $earliestOperationDateByDepartId, $originalLabelsByDepartId);
        $touchedAccountIds = [];
        $touchedCaisseIds = [];

        foreach ($allocationsByOperationId as $operationId => $allocations) {
            $operation = $operations[$operationId];
            $caisseTransaction = $this->singleUnclaimedCaisseWithdrawalMatching($operation);
            $this->claimedCaisseTransactionIds[] = $caisseTransaction->id;
            $touchedAccountIds[] = $operation->account_id;
            $touchedCaisseIds[] = $caisseTransaction->caisse_id;

            $this->splitOperation($operation, $caisseTransaction, $allocations, $costReportsByDepartId);
        }

        $this->printAndRecomputeBalances(array_unique($touchedAccountIds), array_unique($touchedCaisseIds), $accountService);
    }

    private function assertOperationCanBeSplit(?AccountTransaction $operation, int $operationId): void
    {
        if (
            $operation === null
            || $operation->reference_type !== 'SORTIE_DE_CAISSE'
            || $operation->reference_id !== null
            || $operation->transaction_type !== AccountTransactionType::Debit
            || $operation->category !== AccountTransactionCategory::Expense
        ) {
            throw new RuntimeException("La transaction {$operationId} n'est pas une sortie de caisse manuelle (charge, non rattachée).");
        }
    }

    /**
     * @param  array<int, int>  $feeByDepartId
     * @param  array<int, \DateTimeInterface>  $earliestOperationDateByDepartId
     * @param  array<int, array<int, string>>  $originalLabelsByDepartId  free-text labels of the operations that paid each départ, kept in the report's notes
     * @return array<int, DepartCostReport>
     */
    private function createCostReports(array $feeByDepartId, array $earliestOperationDateByDepartId, array $originalLabelsByDepartId): array
    {
        $costReportsByDepartId = [];

        foreach ($feeByDepartId as $departId => $rentalFee) {
            $depart = Depart::withoutGlobalScopes()->with('buses')->find($departId);

            if ($depart === null) {
                throw new RuntimeException("Le départ {$departId} est introuvable.");
            }

            if (DepartCostReport::where('depart_id', $departId)->exists()) {
                throw new RuntimeException("Le départ {$departId} a déjà un bilan.");
            }

            if ($depart->buses->count() !== 1) {
                throw new RuntimeException("Le départ {$departId} doit avoir exactement un bus ({$depart->buses->count()} trouvé(s)).");
            }

            $costReport = (new DepartCostReport)->forceFill([
                'depart_id' => $departId,
                'notes' => 'Libellé d\'origine de la sortie de caisse : '.implode(' ; ', array_unique($originalLabelsByDepartId[$departId])),
                'created_at' => $earliestOperationDateByDepartId[$departId],
                'updated_at' => $earliestOperationDateByDepartId[$departId],
            ]);
            $costReport->save();
            $costReport->busRentals()->create([
                'bus_id' => $depart->buses->first()->id,
                'bus_owner_name' => self::BUS_OWNER_PLACEHOLDER_NAME,
                'rental_fee' => $rentalFee,
                'paid_amount' => $rentalFee,
            ]);

            $costReportsByDepartId[$departId] = $costReport->setRelation('depart', $depart);
            $this->line('  + Bilan « '.$depart->identifier(with_trajet_prefix: true)." » : location {$rentalFee} F ({$depart->date->format('d/m/Y')})");
        }

        return $costReportsByDepartId;
    }

    /**
     * Gives each allocation its own account debit + caisse withdrawal pair, dated like the original
     * operation. The original rows are shrunk to what is left, or — when fully allocated — reused for the
     * last allocation so that nothing is ever deleted.
     *
     * @param  array<int, array{departId: int, amount: int}>  $allocations
     * @param  array<int, DepartCostReport>  $costReportsByDepartId
     */
    private function splitOperation(AccountTransaction $operation, CaisseTransaction $caisseTransaction, array $allocations, array $costReportsByDepartId): void
    {
        $residualAmount = $operation->amount - array_sum(array_column($allocations, 'amount'));

        if ($residualAmount < 0) {
            throw new RuntimeException("L'opération {$operation->id} ({$operation->amount} F) est sur-allouée de ".abs($residualAmount).' F.');
        }

        $locationDeBusTypeId = ExpenseType::idForCode(ExpenseTypeCode::LocationDeBus);
        $originalIsReusedForLastAllocation = $residualAmount === 0;
        $allocationsToCreate = $originalIsReusedForLastAllocation ? array_slice($allocations, 0, -1) : $allocations;

        foreach ($allocationsToCreate as $allocation) {
            $costReport = $costReportsByDepartId[$allocation['departId']];
            $label = DepartCostReportPaymentService::labelForDepart($costReport->depart);

            $this->recordPair(new AccountTransaction, new CaisseTransaction, $operation, $caisseTransaction, $allocation['amount'], $label, $costReport->id, $locationDeBusTypeId);
            $this->line("  = Opération #{$operation->id} → {$label} : {$allocation['amount']} F");
        }

        if ($originalIsReusedForLastAllocation) {
            $lastAllocation = end($allocations);
            $costReport = $costReportsByDepartId[$lastAllocation['departId']];
            $label = DepartCostReportPaymentService::labelForDepart($costReport->depart);

            $this->recordPair($operation, $caisseTransaction, $operation, $caisseTransaction, $lastAllocation['amount'], $label, $costReport->id, $locationDeBusTypeId);
            $this->line("  = Opération #{$operation->id} → {$label} : {$lastAllocation['amount']} F (opération d'origine réutilisée)");

            return;
        }

        $operation->update(['amount' => $residualAmount]);
        $caisseTransaction->update(['amount' => $residualAmount]);
        $this->line("  = Opération #{$operation->id} : reste {$residualAmount} F non rattachés");
    }

    /**
     * Writes an allocation on the given account/caisse rows (a fresh pair, or the original ones), taking
     * user and dates from the original operation.
     */
    private function recordPair(
        AccountTransaction $accountRow,
        CaisseTransaction $caisseRow,
        AccountTransaction $originalOperation,
        CaisseTransaction $originalCaisseTransaction,
        int $amount,
        string $label,
        int $costReportId,
        ?int $expenseTypeId,
    ): void {
        $accountRow->forceFill([
            'account_id' => $originalOperation->account_id,
            'user_id' => $originalOperation->user_id,
            'amount' => $amount,
            'transaction_type' => AccountTransactionType::Debit,
            'category' => AccountTransactionCategory::Expense,
            'expense_type_id' => $expenseTypeId,
            'label' => $label,
            'reference_type' => DepartCostReportPaymentService::ACCOUNT_REFERENCE_TYPE,
            'reference_id' => $costReportId,
            'created_at' => $originalOperation->created_at,
            'updated_at' => $originalOperation->created_at,
        ])->save();

        $caisseRow->forceFill([
            'caisse_id' => $originalCaisseTransaction->caisse_id,
            'user_id' => $originalCaisseTransaction->user_id,
            'amount' => $amount,
            'label' => $label,
            'transaction_type' => CaisseTransactionType::Withdraw,
            'created_at' => $originalCaisseTransaction->created_at,
            'updated_at' => $originalCaisseTransaction->created_at,
        ])->save();
    }

    private function singleUnclaimedCaisseWithdrawalMatching(AccountTransaction $operation): CaisseTransaction
    {
        $candidates = CaisseTransaction::query()
            ->where('transaction_type', CaisseTransactionType::Withdraw->value)
            ->where('label', $operation->label)
            ->where('amount', $operation->amount)
            ->whereNotIn('id', $this->claimedCaisseTransactionIds)
            ->lockForUpdate()
            ->get();

        if ($candidates->count() > 1) {
            $candidates = $candidates->filter(fn (CaisseTransaction $candidate): bool => $candidate->created_at->equalTo($operation->created_at));
        }

        if ($candidates->count() !== 1) {
            throw new RuntimeException("Impossible d'identifier de façon unique la sortie de caisse de l'opération {$operation->id} ({$candidates->count()} correspondances).");
        }

        return $candidates->first();
    }

    /**
     * @param  array<int, int>  $accountIds
     * @param  array<int, int>  $caisseIds
     */
    private function printAndRecomputeBalances(array $accountIds, array $caisseIds, AccountService $accountService): void
    {
        /** @var Collection<int, Account> $accounts */
        $accounts = Account::whereIn('id', $accountIds)->get();

        foreach ($accounts as $account) {
            $previousBalance = $account->balance;
            $this->line("  Compte « {$account->name} » : {$previousBalance} F → ".$account->updateBalanceFromLedger()->balance.' F');
        }

        foreach (Caisse::whereIn('id', $caisseIds)->get() as $caisse) {
            $previousBalance = $caisse->balance;
            $this->line("  Caisse « {$caisse->name} » : {$previousBalance} F → ".$caisse->updateBalanceFromLedger()->balance.' F');
        }

        $accountService->assertGlobalInvariantHolds();
    }
}
