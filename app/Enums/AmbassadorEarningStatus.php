<?php

namespace App\Enums;

/**
 * Pending: created, waiting for the customer's trip to be completed (+24h). Approved: payable.
 * Paid: included in a succeeded payout. Cancelled: voided (refund, cancellation, transfer).
 */
enum AmbassadorEarningStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente',
            self::Approved => 'Approuvé',
            self::Paid => 'Payé',
            self::Cancelled => 'Annulé',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Approved => 'blue',
            self::Paid => 'green',
            self::Cancelled => 'zinc',
        };
    }
}
