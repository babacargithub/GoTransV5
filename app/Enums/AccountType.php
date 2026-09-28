<?php

namespace App\Enums;

enum AccountType: string
{
    /**
     * General-purpose account not tied to a specific automated flow.
     * Examples: loans ("Emprunt"), rent, deposits. The account's `name`
     * column carries the specific purpose.
     */
    case Management = 'MANAGEMENT';

    /**
     * Single company-wide account credited automatically whenever a cash
     * ticket sale deposits into a caisse.
     */
    case TicketSales = 'TICKET_SALES';

    /**
     * Categorized outgoing-cash account (fuel, salaries, maintenance, ...),
     * used as the debit side of a "sortie de caisse".
     */
    case Expense = 'EXPENSE';

    public function label(): string
    {
        return match ($this) {
            self::Management => 'Compte de gestion',
            self::TicketSales => 'Ventes de billets',
            self::Expense => 'Charge',
        };
    }
}
