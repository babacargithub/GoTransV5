<?php

use App\Models\DepartCostReport;
use App\Services\DepartProfitAllocationService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * ONE-TIME production data fix, run by the deployment's `php artisan migrate` so it cannot be forgotten.
 *
 * 1. Splits the bulk "Location de bus du samedi 31 oct au lundi 2 nov" sortie de caisse (account
 *    transaction 866) per départ with ledger:attach-bus-rental-operation --apply. What the four départs
 *    that have a cost report do not cover (the 31 oct buses) stays on the sortie, relabelled and booked
 *    on 31 oct. The step is skipped when the sortie no longer looks like the original (already applied)
 *    and aborts the migration when the command reports an inconsistency, leaving the data untouched.
 * 2. Plans the November 2026 fixed costs (copied from the local planning grid), never overwriting an
 *    amount already planned.
 * 3. Allocates the profit of the cost reports of the four départs fixed in step 1 (and only those: not the
 *    31 oct buses, nor any other November départ), oldest first, exactly as saving a cost report does: into the "Charges" account up to the month's planned costs, the surplus into
 *    "Bénéfices". It must come after step 2 (the planned costs decide the split) and needs the two
 *    accounts created by 2026_11_17_002349_create_benefits_and_charges_accounts, which migrates earlier.
 * 4. Reclassifies the two operations that cancel each other out (the withdrawal wrongly tagged as revenue and
 *    its "Correction d'erreur" entrée) from Revenue to Internal. Only the category changes: no amount, date or
 *    balance moves, so SUM(accounts) == SUM(caisses) is untouched.
 *
 * Everything runs in one transaction: any failure leaves the data exactly as it was.
 */
return new class extends Migration
{
    private const BULK_SORTIE_ACCOUNT_TRANSACTION_ID = 866;

    private const BULK_SORTIE_ORIGINAL_AMOUNT = 621_000;

    private const BULK_SORTIE_ORIGINAL_LABEL = 'Location de bus du samedi 31 oct au lundi 2 nov';

    /** Départs of that weekend that have a cost report: Matin 1 nov, Après-midi 1 nov, 2 nov, Matin 2 nov. */
    private const DEPART_IDS_COVERED_BY_BULK_SORTIE = [4256, 4262, 4270, 4257];

    private const RESIDUAL_LABEL = 'Location de bus du samedi 31 octobre';

    private const RESIDUAL_DATE = '2026-10-31';

    /** @var array<int, array{id: int, transactionType: string, amount: int}> */
    private const CANCELLING_REVENUE_OPERATIONS = [
        ['id' => 863, 'transactionType' => 'DEBIT', 'amount' => 108_600],
        ['id' => 864, 'transactionType' => 'CREDIT', 'amount' => 108_000],
    ];

    private const NOVEMBER_2026 = '2026-11-01';

    /** @var array<string, int> planned amount (FCFA) by expense type code */
    private const NOVEMBER_2026_PLANNED_COSTS = [
        'CREDIT_ET_PASSE_INTERNET' => 10_000,
        'PUBLICITE' => 100_000,
        'SALAIRE_EMPLOYE' => 130_000,
        'TRANSPORT_EMPLOYE' => 30_000,
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->attachBulkBusRentalSortieToDeparts();
            $this->planNovember2026Costs();
            $this->allocateProfitOfDepartsCoveredByBulkSortie();
            $this->reclassifyTheTwoCancellingRevenueOperationsAsInternal();
        });
    }

    public function down(): void
    {
        // One-time data fix: intentionally not reversible.
    }

    private function attachBulkBusRentalSortieToDeparts(): void
    {
        $bulkSortie = DB::table('account_transactions')->where('id', self::BULK_SORTIE_ACCOUNT_TRANSACTION_ID)->first();

        $isStillTheOriginalBulkSortie = $bulkSortie !== null
            && (int) $bulkSortie->amount === self::BULK_SORTIE_ORIGINAL_AMOUNT
            && $bulkSortie->label === self::BULK_SORTIE_ORIGINAL_LABEL;

        if (! $isStillTheOriginalBulkSortie) {
            return;
        }

        $exitCode = Artisan::call('ledger:attach-bus-rental-operation', [
            'accountTransactionId' => self::BULK_SORTIE_ACCOUNT_TRANSACTION_ID,
            '--depart' => self::DEPART_IDS_COVERED_BY_BULK_SORTIE,
            '--residual-label' => self::RESIDUAL_LABEL,
            '--residual-date' => self::RESIDUAL_DATE,
            '--apply' => true,
        ]);

        if ($exitCode !== 0) {
            throw new RuntimeException('Rattachement de la sortie de location de bus impossible : '.trim(Artisan::output()));
        }
    }

    private function planNovember2026Costs(): void
    {
        foreach (self::NOVEMBER_2026_PLANNED_COSTS as $expenseTypeCode => $plannedAmount) {
            $expenseTypeId = DB::table('expense_types')->where('code', $expenseTypeCode)->value('id');

            if ($expenseTypeId === null) {
                continue;
            }

            DB::table('monthly_expense_budgets')->insertOrIgnore([
                'expense_type_id' => $expenseTypeId,
                'month' => self::NOVEMBER_2026,
                'amount' => $plannedAmount,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function allocateProfitOfDepartsCoveredByBulkSortie(): void
    {
        $departProfitAllocationService = app(DepartProfitAllocationService::class);

        DepartCostReport::query()
            ->whereIn('depart_id', self::DEPART_IDS_COVERED_BY_BULK_SORTIE)
            ->with('depart')
            ->get()
            ->sortBy(fn (DepartCostReport $departCostReport): string => $departCostReport->depart->date->toDateTimeString())
            ->each(fn (DepartCostReport $departCostReport) => $departProfitAllocationService->allocate($departCostReport));
    }

    private function reclassifyTheTwoCancellingRevenueOperationsAsInternal(): void
    {
        foreach (self::CANCELLING_REVENUE_OPERATIONS as $operation) {
            DB::table('account_transactions')
                ->where('id', $operation['id'])
                ->where('category', 'REVENUE')
                ->where('transaction_type', $operation['transactionType'])
                ->where('amount', $operation['amount'])
                ->update(['category' => 'INTERNAL']);
        }
    }
};
