<?php

namespace App\Enums;

/**
 * The catalogue of things an ambassador may or may not be allowed to do. The backing string is the stable
 * code stored in `ambassador_abilities.code`; the label is only a seed value the back office may reword.
 */
enum AmbassadorAbilityName: string
{
    case Sponsor = 'sponsor';

    public function defaultLabel(): string
    {
        return match ($this) {
            self::Sponsor => 'Parrainer d\'autres ambassadeurs',
        };
    }
}
