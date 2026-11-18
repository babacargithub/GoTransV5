<?php

use App\Enums\AccountType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Same names DepartProfitAllocationService looks the accounts up by. */
    private const ACCOUNT_NAMES = ['Charges', 'Bénéfices'];

    /**
     * Accounts created by hand in a local database may not exist in production, so each one is only
     * created when no account of that name exists yet.
     */
    public function up(): void
    {
        foreach (self::ACCOUNT_NAMES as $accountName) {
            if (DB::table('accounts')->where('name', $accountName)->exists()) {
                continue;
            }

            DB::table('accounts')->insert([
                'name' => $accountName,
                'account_type' => AccountType::Management->value,
                'balance' => 0,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Only removes the accounts when they are still untouched, so a rollback can never lose money.
     */
    public function down(): void
    {
        foreach (self::ACCOUNT_NAMES as $accountName) {
            $accountId = DB::table('accounts')->where('name', $accountName)->where('balance', 0)->value('id');

            if ($accountId !== null && ! DB::table('account_transactions')->where('account_id', $accountId)->exists()) {
                DB::table('accounts')->where('id', $accountId)->delete();
            }
        }
    }
};
