<?php

namespace App\Enums;

/**
 * Stable codes of the built-in (system) expense types seeded by the
 * create_expense_types_table migration. Code that classifies an expense
 * automatically (bus rental payments, ticket refunds) looks the type up by
 * code, never by name, so staff can rename a type without breaking it.
 */
enum ExpenseTypeCode: string
{
    case LocationDeBus = 'LOCATION_DE_BUS';
    case SalairePdg = 'SALAIRE_PDG';
    case RemboursementTicket = 'REMBOURSEMENT_TICKET';
    case CreditEtPasseInternet = 'CREDIT_ET_PASSE_INTERNET';
    case SalaireEmploye = 'SALAIRE_EMPLOYE';
    case TransportEmploye = 'TRANSPORT_EMPLOYE';
    case Publicite = 'PUBLICITE';

    public function defaultName(): string
    {
        return match ($this) {
            self::LocationDeBus => 'Location de bus',
            self::SalairePdg => 'Salaire PDG',
            self::RemboursementTicket => 'Remboursement ticket',
            self::CreditEtPasseInternet => 'Crédit et passe internet',
            self::SalaireEmploye => 'Salaire employé',
            self::TransportEmploye => 'Transport employé',
            self::Publicite => 'Publicité',
        };
    }
}
