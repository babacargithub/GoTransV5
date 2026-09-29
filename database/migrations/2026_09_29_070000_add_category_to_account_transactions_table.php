<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_transactions', function (Blueprint $table): void {
            // REVENUE | EXPENSE | INTERNAL — set once at creation time by
            // AccountService::credit()/debit(), never edited afterwards.
            // Defaults to INTERNAL so the column can be added NOT NULL without
            // a separate step to change its nullability once backfilled.
            $table->string('category', 10)->default('INTERNAL')->after('transaction_type');
        });

        $this->backfillExistingTransactions();
    }

    private function backfillExistingTransactions(): void
    {
        $accountTypesById = DB::table('accounts')->pluck('account_type', 'id');

        DB::table('account_transactions')
            ->orderBy('id')
            ->chunkById(500, function ($transactions) use ($accountTypesById): void {
                foreach ($transactions as $transaction) {
                    $accountType = $accountTypesById[$transaction->account_id] ?? null;

                    $category = match (true) {
                        $transaction->reference_type === 'INTER_ACCOUNT_TRANSFER' => 'INTERNAL',
                        in_array($accountType, ['TICKET_SALES', 'PARCEL_SERVICE'], true) && $transaction->transaction_type === 'CREDIT' => 'REVENUE',
                        $accountType === 'EXPENSE' && $transaction->transaction_type === 'DEBIT' => 'EXPENSE',
                        default => 'INTERNAL',
                    };

                    DB::table('account_transactions')->where('id', $transaction->id)->update(['category' => $category]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('account_transactions', function (Blueprint $table): void {
            $table->dropColumn('category');
        });
    }
};
