<?php

namespace App\Enums;

/**
 * Predefined caisse operations offered as one-click shortcuts on the Entrée
 * / Sortie de caisse forms: picking one fills the libellé and forces the
 * persisted AccountTransactionCategory, so staff no longer have to rely on
 * AccountService::determineCategory() inferring it from the debited/credited
 * account's nature — which silently produced Internal for a genuine expense
 * debited from a non-Expense-nature account (e.g. a generic cash account).
 */
enum CommonCaisseOperation: string
{
    case LocationDeBus = 'LOCATION_DE_BUS';
    case SalairePdg = 'SALAIRE_PDG';
    case RemboursementTicket = 'REMBOURSEMENT_TICKET';
    case CreditEtPasseInternet = 'CREDIT_ET_PASSE_INTERNET';
    case SalaireEmploye = 'SALAIRE_EMPLOYE';
    case TransportEmploye = 'TRANSPORT_EMPLOYE';
    case Publicite = 'PUBLICITE';
    case PaiementColis = 'PAIEMENT_COLIS';

    public function label(): string
    {
        return match ($this) {
            self::LocationDeBus => 'Location de bus',
            self::SalairePdg => 'Salaire PDG',
            self::RemboursementTicket => 'Remboursement ticket',
            self::CreditEtPasseInternet => 'Crédit et passe internet',
            self::SalaireEmploye => 'Salaire employé',
            self::TransportEmploye => 'Transport employé',
            self::Publicite => 'Publicité',
            self::PaiementColis => 'Paiement colis',
        };
    }

    public function category(): AccountTransactionCategory
    {
        return match ($this) {
            self::PaiementColis => AccountTransactionCategory::Revenue,
            default => AccountTransactionCategory::Expense,
        };
    }

    /**
     * Shortcuts relevant to a "sortie de caisse" (cash-out) form.
     *
     * @return array<int, self>
     */
    public static function forSortieDeCaisse(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $operation): bool => $operation->category() === AccountTransactionCategory::Expense,
        ));
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
