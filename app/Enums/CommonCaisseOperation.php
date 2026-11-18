<?php

namespace App\Enums;

/**
 * Predefined caisse operations offered as one-click shortcuts on the Entrée
 * de caisse form: picking one fills the libellé and forces the persisted
 * AccountTransactionCategory. Expense shortcuts now live in the expense_types
 * table (see ExpenseType / ExpenseTypeCode) and are picked on the Sortie form.
 */
enum CommonCaisseOperation: string
{
    case PaiementColis = 'PAIEMENT_COLIS';

    public function label(): string
    {
        return match ($this) {
            self::PaiementColis => 'Paiement colis',
        };
    }

    public function category(): AccountTransactionCategory
    {
        return match ($this) {
            self::PaiementColis => AccountTransactionCategory::Revenue,
        };
    }

    /**
     * Shortcuts relevant to an "entrée de caisse" (cash-in) form.
     *
     * @return array<int, self>
     */
    public static function forEntreeDeCaisse(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $operation): bool => $operation->category() === AccountTransactionCategory::Revenue,
        ));
    }
}
