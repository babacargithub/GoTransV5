<?php

namespace App\Manager;

use App\Enums\BookingTransferType;
use App\Models\Booking;
use App\Models\BookingTransfer;
use App\Models\Bus;
use App\Models\BusSeat;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class BusManager
{
    public function transferBookings(Bus $sourceBus, Bus $targetBus, $transferData): true|JsonResponse
    {
        if (! isset($transferData['numberOfBookingsToTransfer']) || ! isset($transferData['transferType'])) {
            throw new InvalidArgumentException('numberOfBookingsToTransfer and transferType are required');
        }

        $numberOfBookingsToTransfer = $transferData['numberOfBookingsToTransfer'];
        // if $numberOfBookingsToTransfer is -1, transfer all bookings of the transferType chosen
        $transferType = $transferData['transferType'];
        // check if there are enough seats in the target bus

        // transferType 1 means transfer bookings with no ticket
        // transferType 2 means transfer bookings with ticket
        // transferType 3 means transfer all bookings
        if ($numberOfBookingsToTransfer == -1) {
            $numberOfBookingsToTransfer = $sourceBus->bookings()->where(function ($query) use ($transferType) {
                if ($transferType == 1) {
                    $query->whereNull('ticket_id');
                } elseif ($transferType == 2) {
                    $query->whereNotNull('ticket_id');
                }
            })->count();
        }
        if ($transferType == 2 || $transferType == 3) {
            if ($targetBus->seatsLeft() < $numberOfBookingsToTransfer) {
                return response()->json(['message' => 'Il n\'y a pas assez de places dans le bus cible'], 422);
            }
        }
        $bookingsToTransfer = $sourceBus->bookings()->where(function ($query) use ($transferType) {
            if ($transferType == 1) {
                $query->whereNull('ticket_id');
            } elseif ($transferType == 2) {
                $query->whereNotNull('ticket_id');
            }
        })->limit($numberOfBookingsToTransfer)
            ->orderByDesc('created_at')->get();

        $availableSeats = $targetBus->seats()->where('booked', false)->get();
        $actingUserId = auth()->id();
        DB::transaction(function () use ($bookingsToTransfer, $sourceBus, $targetBus, $availableSeats, $actingUserId) {
            $bookingsToTransfer->each(function (Booking $booking) use ($sourceBus, $targetBus, $availableSeats, $actingUserId) {

                $sourceSeatId = $booking->seat_id;
                $sourceSeatNumber = $booking->seat_number;

                $booking->bus_id = $targetBus->id;
                $booking->depart_id = $targetBus->depart_id;
                if ($booking->has_seat || $booking->has_ticket) {
                    $seat = $booking->seat;
                    $seat?->freeSeat();
                    $seat?->save();
                    $booking->seat_id = null; // get one available seat et put the cursor to the next seat
                    if ($booking->has_ticket) {
                        $newSeat = $availableSeats->shift();
                        if ($newSeat instanceof BusSeat) {
                            $newSeat->book();
                            $newSeat->save();
                            $booking->seat_id = $newSeat->id;
                            $booking->save();
                        } else {
                            throw new UnprocessableEntityHttpException('Impossible de trouver un siège pour la réservation !',
                                null);
                        }
                    } else {
                    }
                }
                $booking->save();

                BookingTransfer::create([
                    'booking_id' => $booking->id,
                    'source_bus_id' => $sourceBus->id,
                    'target_bus_id' => $targetBus->id,
                    'source_seat_id' => $sourceSeatId,
                    'target_seat_id' => $booking->seat_id,
                    'source_seat_number' => $sourceSeatNumber,
                    // Read fresh from the DB rather than $booking->seat_number: that accessor caches
                    // the "seat" relation, which would still hold the just-freed source seat here.
                    'target_seat_number' => $booking->seat_id !== null ? BusSeat::find($booking->seat_id)?->number : null,
                    'transfer_type' => BookingTransferType::Bulk,
                    'user_id' => $actingUserId,
                    'transferred_at' => now(),
                ]);
            });
        });

        return true;

    }
}
