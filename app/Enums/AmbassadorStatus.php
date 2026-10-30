<?php

namespace App\Enums;

/**
 * Lifecycle of an ambassador. A sponsored ambassador starts as PendingApproval until the back office
 * approves them; only Active ambassadors with a running contract earn on new customers.
 */
enum AmbassadorStatus: string
{
    case PendingApproval = 'pending_approval';
    case Active = 'active';
    case Suspended = 'suspended';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::PendingApproval => 'En attente de validation',
            self::Active => 'Actif',
            self::Suspended => 'Suspendu',
            self::Rejected => 'Refusé',
        };
    }

    /**
     * Flux badge color for displaying this status in the back office.
     */
    public function badgeColor(): string
    {
        return match ($this) {
            self::PendingApproval => 'amber',
            self::Active => 'green',
            self::Suspended => 'orange',
            self::Rejected => 'red',
        };
    }
}
