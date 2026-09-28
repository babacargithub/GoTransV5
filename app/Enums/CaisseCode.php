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
}
