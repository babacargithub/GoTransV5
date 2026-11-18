<?php

use App\Enums\ExpenseTypeCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Free-text label prefixes of historical SORTIE_DE_CAISSE expenses, matched
     * case-insensitively, mapped to the type they belong to. Anything that matches
     * none of them stays unclassified (null) and shows as "Non classé" in reports.
     *
     * @var array<string, array<int, string>>
     */
    private const LABEL_PREFIXES_BY_TYPE_CODE = [
        'LOCATION_DE_BUS' => ['Location de bus', 'Location bus'],
        'SALAIRE_PDG' => ['Salaire PDG'],
        'REMBOURSEMENT_TICKET' => ['Compensation tickets'],
        'CREDIT_ET_PASSE_INTERNET' => ['Crédit et passe internet', 'Renouvellement nom de domaine'],
        'SALAIRE_EMPLOYE' => ['Salaire employé'],
        'TRANSPORT_EMPLOYE' => ['Transport employé'],
        'PUBLICITE' => ['Publicité'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('expense_types')) {
            Schema::create('expense_types', function (Blueprint $table): void {
                $table->id();

                // Stable programmatic identifier of the built-in types (see ExpenseTypeCode).
                // Null for types created later through the UI.
                $table->string('code')->nullable()->unique();
                $table->string('name')->unique();
                $table->boolean('is_system')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        Schema::table('account_transactions', function (Blueprint $table): void {
            // Only set on EXPENSE transactions. Nullable: historical rows that could not
            // be classified, and non-expense rows, carry no type.
            $table->foreignId('expense_type_id')
                ->nullable()
                ->after('category')
                ->constrained('expense_types')
                ->restrictOnDelete();
        });

        $this->seedSystemExpenseTypes();
        $this->backfillExistingExpenseTransactions();
    }

    private function seedSystemExpenseTypes(): void
    {
        $now = now();

        foreach (ExpenseTypeCode::cases() as $code) {
            DB::table('expense_types')->insertOrIgnore([
                'code' => $code->value,
                'name' => $code->defaultName(),
                'is_system' => true,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function backfillExistingExpenseTransactions(): void
    {
        $expenseTypeIdsByCode = DB::table('expense_types')->pluck('id', 'code');

        $this->classifyByReferenceType('DEPART_COST_REPORT', $expenseTypeIdsByCode[ExpenseTypeCode::LocationDeBus->value]);
        $this->classifyByReferenceType('TICKET_REFUND', $expenseTypeIdsByCode[ExpenseTypeCode::RemboursementTicket->value]);

        foreach (self::LABEL_PREFIXES_BY_TYPE_CODE as $typeCode => $labelPrefixes) {
            foreach ($labelPrefixes as $labelPrefix) {
                DB::table('account_transactions')
                    ->where('category', 'EXPENSE')
                    ->where('reference_type', 'SORTIE_DE_CAISSE')
                    ->whereNull('expense_type_id')
                    ->where('label', 'like', $labelPrefix.'%')
                    ->update(['expense_type_id' => $expenseTypeIdsByCode[$typeCode]]);
            }
        }
    }

    private function classifyByReferenceType(string $referenceType, int $expenseTypeId): void
    {
        DB::table('account_transactions')
            ->where('category', 'EXPENSE')
            ->where('reference_type', $referenceType)
            ->update(['expense_type_id' => $expenseTypeId]);
    }

    public function down(): void
    {
        Schema::table('account_transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('expense_type_id');
        });

        Schema::dropIfExists('expense_types');
    }
};
