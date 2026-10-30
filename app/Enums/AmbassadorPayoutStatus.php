<?php

namespace App\Enums;

/**
 * Status of a Wave payout requested for an ambassador.
 */
enum AmbassadorPayoutStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'À envoyer',
            self::Processing => 'En cours',
            self::Succeeded => 'Payé',
            self::Failed => 'Échoué',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Processing => 'blue',
            self::Succeeded => 'green',
            self::Failed => 'red',
        };
    }
}
