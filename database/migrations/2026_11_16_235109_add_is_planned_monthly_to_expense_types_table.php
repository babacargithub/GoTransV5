<?php

use App\Enums\ExpenseTypeCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Built-in types the company plans a monthly amount for; the others (bus rentals, refunds) stay unplanned. */
    private const PLANNED_MONTHLY_TYPE_CODES = [
        ExpenseTypeCode::SalairePdg,
        ExpenseTypeCode::SalaireEmploye,
        ExpenseTypeCode::TransportEmploye,
        ExpenseTypeCode::CreditEtPasseInternet,
        ExpenseTypeCode::Publicite,
    ];

    public function up(): void
    {
        Schema::table('expense_types', function (Blueprint $table): void {
            // Only types flagged here appear in the "Gestion dépenses" monthly planning grid.
            $table->boolean('is_planned_monthly')->default(false)->after('is_active');
        });

        DB::table('expense_types')
            ->whereIn('code', array_map(fn (ExpenseTypeCode $code): string => $code->value, self::PLANNED_MONTHLY_TYPE_CODES))
            ->update(['is_planned_monthly' => true]);
    }

    public function down(): void
    {
        Schema::table('expense_types', function (Blueprint $table): void {
            $table->dropColumn('is_planned_monthly');
        });
    }
};
