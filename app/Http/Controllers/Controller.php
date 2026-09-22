<?php

namespace App\Http\Controllers;

use App\Http\Resources\BookingResource;
use App\Models\Booking;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    /**
     * Back-office bookings export with the legacy filter + format options: the
     * `paye` query param keeps only paid ("1") or only unpaid ("0") bookings, and
     * `format=text` returns a tab-separated .txt download instead of the printable
     * PDF page. Shared by the départ export and the bus export.
     *
     * @param  Collection<int, Booking>  $bookings  bookings already ordered by the caller
     * @param  array<int, string>  $headerLines  context lines shown under the title
     */
    protected function filteredBookingsExportResponse(
        Collection $bookings,
        Request $request,
        string $documentTitle,
        array $headerLines,
        string $downloadFileNameWithoutExtension,
    ): Response|StreamedResponse {
        $paidFilter = $request->query('paye');

        if ($paidFilter === '1') {
            $bookings = $bookings->filter(fn (Booking $booking): bool => $booking->has_ticket)->values();
            $headerLines[] = 'Filtre : réservations payées';
        } elseif ($paidFilter === '0') {
            $bookings = $bookings->filter(fn (Booking $booking): bool => ! $booking->has_ticket)->values();
            $headerLines[] = 'Filtre : réservations non payées';
        }

        if ($request->query('format') === 'text') {
            return $this->bookingsExportTextResponse($bookings, $downloadFileNameWithoutExtension);
        }

        return $this->bookingsExportDocumentResponse($bookings, $documentTitle, $headerLines, $downloadFileNameWithoutExtension);
    }

    /**
     * Tab-separated .txt download of the bookings, mirroring the legacy
     * BookingsExporter.exportBookingsAsText output (siège, client, téléphone,
     * point de départ, horaire, point de rendez-vous, liens de paiement,
     * destination).
     *
     * @param  Collection<int, Booking>  $bookings
     */
    protected function bookingsExportTextResponse(Collection $bookings, string $downloadFileNameWithoutExtension): StreamedResponse
    {
        $lines = $bookings->map(function (Booking $booking): string {
            return implode("\t", [
                $booking->seat?->number ?? '',
                $booking->passenger_full_name,
                $booking->customer->phone_number,
                $booking->point_dep->name,
                $booking->formatted_schedule,
                $booking->point_dep->arret_bus,
                'https://globeone.site/payer/'.$booking->id,
                'https://globeone.site/payer/om/'.$booking->id,
                $booking->destination->name,
            ]);
        })->implode("\n");

        $fileName = Str::slug($downloadFileNameWithoutExtension).'.txt';

        return response()->streamDownload(function () use ($lines): void {
            echo $lines;
        }, $fileName, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
