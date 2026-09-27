<?php

namespace App\Enums;

/**
 * Whether a booking was made alone or together with other passengers in the same purchase.
 *
 * Set once when the booking is created and never recomputed: cancelling one member of a group leaves
 * the others (and the cancelled row) typed as Group. A lone traveller's round trip (outbound + return
 * rows sharing a group_id) is still Single — see the `is_main_booking` column for the group's lead.
 */
enum BookingType: string
{
    case Single = 'single';
    case Group = 'group';
}
