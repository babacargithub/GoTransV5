<?php

namespace App\Data\Account;

use App\Models\AccountTransaction;
use Illuminate\Support\Collection;

readonly class AccountTransactionsResultDTO
{
    /**
     * @param  Collection<int, AccountTransaction>  $transactions
     */
    public function __construct(
        public Collection $transactions,
        public int $totalDeposits,
        public int $totalWithdrawals,
    ) {}
}
