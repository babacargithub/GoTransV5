<?php

namespace App\Data\Ambassador;

/**
 * An ambassador's account balance, in FCFA. Cancelled earnings are never counted. The total is what is still held (available + pending); the paid balance is what was already received.
 */
readonly class AmbassadorBalanceDTO
{
    public function __construct(
        public int $totalBalance,
        public int $availableBalance,
        public int $pendingBalance,
        public int $paidBalance,
    ) {}
}
