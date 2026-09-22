{{--
    Printable bookings document, shared by the départ export and the bus export.
    The calling controller passes a different $documentTitle, $headerLines and
    $downloadFileName; the table columns are always the same:
    siège, nom complet (normalisé), téléphone, point de départ, payé par.

    There is no server-side PDF renderer in this project (no new dependency was
    added), so the page is print-optimised and auto-opens the browser print
    dialog — "Enregistrer au format PDF" produces the PDF file.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $documentTitle }}</title>
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 32px;
            font-family: -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            color: #18181b;
            background: #fff;
        }

        .document-header { margin-bottom: 24px; border-bottom: 2px solid #18181b; padding-bottom: 16px; }
        .document-title { margin: 0 0 8px; font-size: 20px; }
        .document-meta { margin: 2px 0; font-size: 13px; color: #3f3f46; }

        .print-toolbar { margin-bottom: 20px; }
        .print-toolbar button {
            padding: 8px 16px;
            font-size: 13px;
            font-weight: 600;
            color: #fff;
            background: #4f46e5;
            border: 0;
            border-radius: 6px;
            cursor: pointer;
        }

        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        thead th {
            text-align: left;
            padding: 8px 10px;
            background: #f4f4f5;
            border-bottom: 1px solid #d4d4d8;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        tbody td { padding: 7px 10px; border-bottom: 1px solid #e4e4e7; vertical-align: top; }
        tbody tr:nth-child(even) { background: #fafafa; }

        .seat-cell { font-weight: 700; white-space: nowrap; }
        .muted { color: #a1a1aa; }
        .empty-state { margin-top: 24px; font-size: 13px; color: #3f3f46; }

        .document-footer { margin-top: 24px; font-size: 11px; color: #71717a; }

        @media print {
            body { padding: 0; }
            .print-toolbar { display: none; }
            thead { display: table-header-group; }
            tbody tr { break-inside: avoid; }
        }
    </style>
</head>
<body>
    <div class="print-toolbar">
        <button type="button" onclick="window.print()">Imprimer / Enregistrer en PDF</button>
    </div>

    <div class="document-header">
        <h1 class="document-title">{{ $documentTitle }}</h1>
        @foreach ($headerLines as $headerLine)
            <p class="document-meta">{{ $headerLine }}</p>
        @endforeach
        <p class="document-meta">Édité le {{ now()->translatedFormat('d F Y à H:i') }}</p>
        <p class="document-meta">{{ trans_choice(':count réservation|:count réservations', $bookings->count(), ['count' => $bookings->count()]) }}</p>
    </div>

    @if ($bookings->isEmpty())
        <p class="empty-state">Aucune réservation à exporter pour le moment.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Siège</th>
                    <th>Nom complet</th>
                    <th>Téléphone</th>
                    <th>Point de départ</th>
                    <th>Payé par</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($bookings as $booking)
                    <tr>
                        <td class="seat-cell">
                            {{ $booking->seat_number ?? '—' }}
                        </td>
                        <td>{{ normalize_passenger_display_name($booking->passenger_full_name) }}</td>
                        <td>{{ $booking->customer->phone_number }}</td>
                        <td>{{ $booking->point_dep->name }}</td>
                        <td>
                            @php($paidVia = $booking->ticket?->payment_method ?: $booking->ticket?->soldBy)
                            @if ($paidVia)
                                {{ $paidVia }}
                            @else
                                <span class="muted">Non payé</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <p class="document-footer">{{ $downloadFileName }}</p>

    <script>
        window.addEventListener('load', function () {
            window.setTimeout(function () { window.print(); }, 300);
        });
    </script>
</body>
</html>
