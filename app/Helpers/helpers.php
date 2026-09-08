<?php

use Illuminate\Support\Str;

function is_request_for_gp_customers(): bool
{
    return request()->headers->has('source') && request()->headers->get('source') == 'gp';

}

/**
 * Formats a passenger name as "Firstname Parts LASTNAME": every first-name part is
 * capitalised (multipart first names included) and the last word is fully uppercased.
 *
 * Shared by the back office bus passengers page and the bookings export documents so
 * both display passenger names the exact same way.
 */
function normalize_passenger_display_name(string $rawPassengerName): string
{
    $passengerNameParts = preg_split('/\s+/', trim($rawPassengerName), flags: PREG_SPLIT_NO_EMPTY) ?: [];

    if ($passengerNameParts === []) {
        return $rawPassengerName;
    }

    if (count($passengerNameParts) === 1) {
        return Str::title($passengerNameParts[0]);
    }

    $lastName = Str::upper(array_pop($passengerNameParts));
    $firstName = Str::title(implode(' ', $passengerNameParts));

    return $firstName.' '.$lastName;
}
