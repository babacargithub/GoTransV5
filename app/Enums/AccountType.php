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
     * Single company-wide account for parcel-service income. Not yet wired
     * to an automatic job (no parcel booking flow exists yet) — credited
     * manually via "entrée de caisse" in the meantime, same as any other
     * account, so it is ready once parcel sales are automated.
     */
    case ParcelService = 'PARCEL_SERVICE';

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
            self::ParcelService => 'Service de colis',
            self::Expense => 'Charge',
        };
    }

    /**
     * The side of "revenue − expenses = profit" this account type belongs
     * to, used by ProfitService. Null for Management: loans, deposits and
     * rent are not real company income or expense.
     */
    public function nature(): ?AccountNature
    {
        return match ($this) {
            self::TicketSales, self::ParcelService => AccountNature::Income,
            self::Expense => AccountNature::Expense,
            self::Management => null,
        };
    }
}
