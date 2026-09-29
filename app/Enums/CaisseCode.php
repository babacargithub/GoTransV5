<?php

namespace App\Enums;

/**
 * Stable identifiers for the built-in system caisses, seeded by the
 * create_caisses_table migration. Payment-method routing (see
 * App\Jobs\RecordTicketPaymentInCaisse) looks caisses up by this code, never
 * by their (editable) display name.
 */
enum CaisseCode: string
{
    case Wave = 'WAVE';
    case OrangeMoney = 'OM';
    case TicketCash = 'TICKET_CASH';
    case Principale = 'PRINCIPALE';

    /**
     * The caisse a ticket's payment lands in / must be reversed from, based on
     * its payment method. Shared by RecordTicketPaymentInCaisse (deposit) and
     * AccountService::reverseTicketSaleForRefund (withdrawal on refund) so the
     * routing rule can't drift between the two.
     */
    public static function forTicketPaymentMethod(?string $paymentMethod): ?self
    {
        return match (strtolower(trim((string) $paymentMethod))) {
            'cash', 'especes', 'espèces' => self::TicketCash,
            'wave' => self::Wave,
            'om' => self::OrangeMoney,
            default => null,
        };
    }
}
