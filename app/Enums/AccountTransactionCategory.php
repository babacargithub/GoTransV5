<?php

namespace App\Enums;

/**
 * Persisted, single-source-of-truth classification for an AccountTransaction,
 * set once at creation time by AccountService::determineCategory() and never
 * edited afterwards. ProfitService sums Revenue minus Expense straight off
 * this column — no join or reference_type exclusion list needed at read time.
 *
 * Internal covers inter-account transfers and any other credit/debit that
 * doesn't represent real company income or expense (e.g. funding an expense
 * account before it's spent, or a Management account movement like a loan).
 */
enum AccountTransactionCategory: string
{
    case Revenue = 'REVENUE';
    case Expense = 'EXPENSE';
    case Internal = 'INTERNAL';

    public function label(): string
    {
        return match ($this) {
            self::Revenue => 'Revenu',
            self::Expense => 'Charge',
            self::Internal => 'Interne',
        };
    }

    /**
     * Flux badge color for displaying this category in the back office.
     */
    public function badgeColor(): string
    {
        return match ($this) {
            self::Revenue => 'blue',
            self::Expense => 'orange',
            self::Internal => 'zinc',
        };
    }
}
