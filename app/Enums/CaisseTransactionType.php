<?php

namespace App\Enums;

enum CaisseTransactionType: string
{
    /** Money flows into the caisse (balance increases). */
    case Deposit = 'DEPOSIT';

    /** Money flows out of the caisse (balance decreases). */
    case Withdraw = 'WITHDRAW';
}
