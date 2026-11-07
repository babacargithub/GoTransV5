<?php

namespace App\Enums;

/**
 * Status of a Wave payout requested for an ambassador. A payout Wave has accepted but not yet settled
 * stays Pending (it then holds a provider_payout_id).
 */
enum AmbassadorPayoutStatus: string
{
    case Pending = 'PENDING';
    case Success = 'SUCCESS';
    case Failed = 'FAILED';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En cours',
            self::Success => 'Payé',
            self::Failed => 'Échoué',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Success => 'green',
            self::Failed => 'red',
        };
    }
}
