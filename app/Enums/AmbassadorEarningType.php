<?php

namespace App\Enums;

/**
 * Kind of line in an ambassador's earnings ledger.
 */
enum AmbassadorEarningType: string
{
    case Acquisition = 'acquisition';
    case SponsorshipBonus = 'sponsorship_bonus';

    public function label(): string
    {
        return match ($this) {
            self::Acquisition => "Prime d'acquisition",
            self::SponsorshipBonus => 'Prime de parrainage',
        };
    }
}
