<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vos billets | Globe One Transport</title>
    <style>
        :root {
            --primary: #0f172a;
            --accent: #14b8c4;
            --muted: #64748b;
            --border: #e2e8f0;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 32px 16px;
            background: #f1f5f9;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            color: var(--primary);
        }
        h1 {
            text-align: center;
            font-size: 22px;
            margin: 0 0 24px;
        }
        .tickets {
            display: flex;
            flex-wrap: wrap;
            gap: 24px;
            justify-content: center;
        }
        .ticket-wrapper {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
        }
        .ticket-card {
            width: 340px;
            background: #ffffff;
            border-radius: 20px;
            padding: 24px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.12);
        }
        .ticket-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding-bottom: 16px;
            border-bottom: 2px solid var(--primary);
        }
        .ticket-header .logo {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: var(--primary);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 18px;
            flex-shrink: 0;
        }
        .ticket-header .brand {
            font-weight: 800;
            font-size: 15px;
            line-height: 1.25;
            text-transform: uppercase;
        }
        .ticket-title {
            text-align: center;
            padding: 16px 0;
            border-bottom: 2px solid var(--primary);
        }
        .ticket-title .kind {
            font-weight: 800;
            font-size: 20px;
            letter-spacing: 0.02em;
        }
        .ticket-title .number {
            font-weight: 700;
            font-size: 13px;
            margin-top: 4px;
        }
        .ticket-route {
            font-weight: 800;
            font-size: 22px;
            padding: 16px 0 4px;
        }
        .ticket-rows {
            padding-bottom: 12px;
        }
        .ticket-row {
            padding: 10px 0;
        }
        .ticket-row .label {
            color: var(--accent);
            font-weight: 600;
            font-size: 14px;
        }
        .ticket-row .value {
            font-weight: 800;
            font-size: 17px;
        }
        .ticket-qr {
            border-top: 2px solid var(--primary);
            padding-top: 20px;
            display: flex;
            justify-content: center;
        }
        .ticket-qr img {
            width: 180px;
            height: 180px;
            border: 1px solid var(--border);
            border-radius: 8px;
        }
        .ticket-footer {
            text-align: center;
            font-size: 12px;
            color: var(--muted);
            padding-top: 16px;
        }
        .download-btn {
            width: 340px;
            padding: 12px;
            border: none;
            border-radius: 10px;
            background: var(--primary);
            color: #fff;
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
        }
        .download-btn:active {
            opacity: 0.85;
        }
        @media print {
            .download-btn { display: none; }
            body { background: #fff; }
        }
    </style>
</head>
<body>

<h1>Vos billets de voyage</h1>

<div class="tickets">
    @foreach($bookings as $booking)
        @php
            $trajet = $booking->depart->trajet;
            $routeLabel = $trajet->public_name ?? $trajet->name;
            $rendezVous = $booking->point_dep->arret_bus ?? $booking->point_dep->name;
        @endphp
        <div class="ticket-wrapper">
            <div class="ticket-card" id="ticket-{{ $booking->id }}">
                <div class="ticket-header">
                    <div class="logo">GT</div>
                    <div class="brand">Globe One<br>Transport</div>
                </div>

                <div class="ticket-title">
                    <div class="kind">BILLET DE VOYAGE</div>
                    <div class="number">N°: {{ $booking->ticket->number }}</div>
                </div>

                <div class="ticket-route">{{ $routeLabel }}</div>

                <div class="ticket-rows">
                    <div class="ticket-row">
                        <div class="label">Date de Voyage</div>
                        <div class="value">{{ ucfirst($booking->depart->date->translatedFormat('l j F Y')) }}</div>
                    </div>
                    <div class="ticket-row">
                        <div class="label">Point de Rendez-vous</div>
                        <div class="value">{{ $rendezVous }}</div>
                    </div>
                    <div class="ticket-row">
                        <div class="label">Heure</div>
                        <div class="value">{{ $booking->formatted_schedule }}</div>
                    </div>
                    <div class="ticket-row">
                        <div class="label">Passager</div>
                        <div class="value">{{ $booking->passenger_full_name }}</div>
                    </div>
                    <div class="ticket-row">
                        <div class="label">Siège</div>
                        <div class="value">{{ $booking->seat_number ?? 'Non assigné' }}</div>
                    </div>
                    <div class="ticket-row">
                        <div class="label">Prix du billet</div>
                        <div class="value">{{ number_format($booking->ticket->price, 0, ',', ' ') }} F CFA</div>
                    </div>
                </div>

                <div class="ticket-qr">
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data={{ urlencode('BILLET-' . $booking->ticket->number) }}"
                         alt="QR code du billet">
                </div>

                <div class="ticket-footer">
                    Présentez ce billet (imprimé ou sur votre téléphone) à l'embarquement.
                </div>
            </div>

            <button class="download-btn" onclick="downloadTicket('ticket-{{ $booking->id }}', '{{ $booking->ticket->number }}')">
                Télécharger ce billet
            </button>
        </div>
    @endforeach
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script>
    function downloadTicket(elementId, ticketNumber) {
        const node = document.getElementById(elementId);
        html2canvas(node, {backgroundColor: '#ffffff', scale: 2}).then(function (canvas) {
            const link = document.createElement('a');
            link.download = 'billet-' + ticketNumber + '.png';
            link.href = canvas.toDataURL('image/png');
            link.click();
        });
    }
</script>

</body>
</html>
