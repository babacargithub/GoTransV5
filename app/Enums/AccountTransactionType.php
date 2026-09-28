<?php

namespace App\Enums;

enum AccountTransactionType: string
{
    /** Money flows into the account (balance increases). */
    case Credit = 'CREDIT';

    /** Money flows out of the account (balance decreases). */
    case Debit = 'DEBIT';
}
