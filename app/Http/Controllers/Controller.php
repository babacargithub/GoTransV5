<?php

namespace App\Http\Controllers;

use App\Http\Resources\BookingResource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Collection;

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    /**
     * Standard JSON response for any endpoint that lists Booking models (by bus, by group, ...),
     * so every listing shares the exact same response shape.
     */
    protected function bookingsResponse(Collection $bookings): \Illuminate\Http\JsonResponse
    {
        return response()->json(BookingResource::collection($bookings));
    }
}
