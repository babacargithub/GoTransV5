<?php

namespace App\Enums;

/**
 * Lifecycle of an ambassador. A sponsored ambassador starts as Invited until they accept their sponsor's
 * invitation, then stays PendingApproval until the back office approves them; only Active ambassadors with a running contract earn on new customers.
 */
enum AmbassadorStatus: string
{
    case Invited = 'invited';
    case PendingApproval = 'pending_approval';
    case Active = 'active';
    case Suspended = 'suspended';
    case Rejected = 'rejected';

    /**
     * Statuses of ambassadors who are paid what they earned. A suspended ambassador keeps their right to the
     * money already earned; they just stop earning more.
     *
     * @return list<self>
     */
    public static function payableStatuses(): array
    {
        return [self::Active, self::Suspended];
    }

    public function label(): string
    {
        return match ($this) {
            self::Invited => 'Invitation envoyée',
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
            self::Invited => 'zinc',
            self::PendingApproval => 'amber',
            self::Active => 'green',
            self::Suspended => 'orange',
            self::Rejected => 'red',
        };
    }
}
