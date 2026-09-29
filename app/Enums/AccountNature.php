<?php

namespace App\Enums;

/**
 * Classifies an AccountType for profit reporting: which side of
 * "revenue − expenses = profit" its accounts belong to. See
 * AccountType::nature() — Management has no nature (null), since loans,
 * deposits and rent float money between caisses and accounts without
 * representing real company income or expense.
 */
enum AccountNature: string
{
    case Income = 'INCOME';
    case Expense = 'EXPENSE';
}
