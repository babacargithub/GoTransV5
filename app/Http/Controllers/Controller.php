<?php

namespace App\Http\Controllers;

use App\Http\Resources\BookingResource;
use App\Models\Booking;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    /**
     * Standard JSON response for any endpoint that lists Booking models (by bus, by group, ...),
     * so every listing shares the exact same response shape.
     */
    protected function bookingsResponse(Collection $bookings): JsonResponse
    {
        return response()->json(BookingResource::collection($bookings));
    }

    /**
     * Printable bookings document shared by the départ export and the bus export.
     *
     * The caller decides the wording — DepartController passes only the départ name,
     * BusController passes both the départ name and the bus name — while the table
     * columns (siège, nom complet normalisé, téléphone, point de départ, payé par)
     * stay identical. Rendered as a print-optimised HTML page opened inline so the
     * operator saves it as a PDF from the browser; there is no server-side PDF
     * renderer in this project.
     *
     * @param  Collection<int, Booking>  $bookings  bookings already ordered by the caller
     * @param  array<int, string>  $headerLines  context lines shown under the title
     */
    protected function bookingsExportDocumentResponse(
        Collection $bookings,
        string $documentTitle,
        array $headerLines,
        string $downloadFileNameWithoutExtension,
    ): Response {
        $renderedDocument = view('back-office.exports.bookings', [
            'bookings' => $bookings,
            'documentTitle' => $documentTitle,
            'headerLines' => $headerLines,
            'downloadFileName' => Str::slug($downloadFileNameWithoutExtension).'.pdf',
        ])->render();

        return response($renderedDocument)->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
