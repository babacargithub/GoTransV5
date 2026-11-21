<?php

namespace App\Enums;

/**
 * Kind of line in an ambassador's earnings ledger.
 */
enum AmbassadorEarningType: string
{
    case Acquisition = 'acquisition';
    case SponsorshipBonus = 'sponsorship_bonus';
    case ExistingCustomerBooking = 'existing_customer_booking';
    case AdvertisementShare = 'advertisement_share';
    case VideoContent = 'video_content';

    public function label(): string
    {
        return match ($this) {
            self::Acquisition => 'Commission pour nouveau client',
            self::SponsorshipBonus => 'Bonus de parrainage',
            self::ExistingCustomerBooking => 'Réservation d\'un client existant',
            self::AdvertisementShare => 'Partage de publicité',
            self::VideoContent => 'Vidéo de promotion',
        };
    }

    /**
     * Whether this reward is recorded by hand by the back office once the ambassador has done the promotion.
     */
    public function isRecordedManually(): bool
    {
        return match ($this) {
            self::AdvertisementShare, self::VideoContent => true,
            default => false,
        };
    }
}
