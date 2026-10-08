<?php

namespace App\Http\Controllers;

use App\Models\TicketPayment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a manual-payment proof screenshot. Proofs live on the private disk
 * (financial documents), so they are only reachable through this authenticated
 * back-office route.
 */
class PaymentProofController extends Controller
{
    public function show(TicketPayment $ticketPayment, int $proofIndex): StreamedResponse
    {
        $proofPath = data_get($ticketPayment->proofs, $proofIndex);

        abort_if(! is_string($proofPath) || ! Storage::disk('local')->exists($proofPath), 404);

        return Storage::disk('local')->response($proofPath);
    }
}
