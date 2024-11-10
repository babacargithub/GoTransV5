<?php

namespace App\Manager;

use App\Models\Booking;
use App\Models\Bus;
use App\Models\BusSeat;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class BusManager
{
    public function transferBookings(Bus $sourceBus, Bus $targetBus, $transferData): true|JsonResponse
    {
        if (!isset($transferData['numberOfBookingsToTransfer']) || !isset($transferData['transferType'])) {
            throw new InvalidArgumentException("numberOfBookingsToTransfer and transferType are required");
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
            ->orderByDesc("created_at")->get();

        $availableSeats = $targetBus->seats()->where('booked', false)->get();
        DB::transaction(function () use ($bookingsToTransfer, $targetBus, $availableSeats) {
            $bookingsToTransfer->each(function (Booking $booking) use ($targetBus, $availableSeats) {

                $booking->bus_id = $targetBus->id;
                $booking->depart_id = $targetBus->depart_id;
                if ($booking->has_seat || $booking->has_ticket) {
                    $seat = $booking->seat;
                    $seat?->freeSeat();
                    $seat?->save();
                    $booking->seat_id = null;// get one available seat et put the cursor to the next seat
                    if ($booking->has_ticket) {
                        $newSeat = $availableSeats->shift();
                        if ($newSeat instanceof BusSeat) {
                            $newSeat->book();
                            $newSeat->save();
                            $booking->seat_id = $newSeat->id;
                            $booking->save();
                        } else {
                            throw new UnprocessableEntityHttpException("Impossible de trouver un siège pour la réservation !",
                                null);
                        }
                    } else {
                    }
                }
                $booking->save();
            });
        });

        return true;

    }

}