<?php

namespace App\Jobs;

use App\Enums\AccountTransactionCategory;
use App\Enums\CaisseCode;
use App\Manager\TicketManager;
use App\Models\AccountTransaction;
use App\Models\Caisse;
use App\Models\Ticket;
use App\Services\AccountService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Deposits a ticket payment into the caisse matching its payment method, and
 * credits the TicketSales account. Dispatched from BookingManager::assignTicketToBooking
 * (automatic online/cash sales) and BookingController::saveTicketPayment (manual
 * staff-recorded payments, including Wave/OM recovered from a failed callback) —
 * always as a queued job, so a caisse/account problem can never block or delay
 * issuing the ticket itself.
 *
 * Caisse routing:
 *  - cash/especes -> Ticket Cash caisse, full ticket price.
 *  - wave         -> Wave caisse, NET of Wave's cut. The ticket price already
 *                     includes the same fee rate as a surcharge
 *                     (TicketManager::calculateTicketPrice), so the net amount
 *                     actually settled to us is price / (1 + WAVE_FEES).
 *  - om           -> OM caisse, full ticket price — Orange Money does not
 *                     deduct anything at payment time, only later when we
 *                     withdraw from the merchant balance.
 *
 * The credit is always persisted as AccountTransactionCategory::Revenue,
 * regardless of the TicketSales account's configured type/nature — a ticket
 * sale is always real company revenue and must never silently fall back to
 * Internal (see AccountService::determineCategory()).
 */
class RecordTicketPaymentInCaisse implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly int $ticketId,
        private readonly string $referenceType = 'TICKET_SALE',
        private readonly ?string $label = null,
        private readonly ?int $userId = null,
    ) {}

    public function handle(AccountService $accountService): void
    {
        // Idempotent: a retried job must not double-deposit the same ticket.
        $alreadyRecorded = AccountTransaction::query()
            ->where('reference_type', $this->referenceType)
            ->where('reference_id', $this->ticketId)
            ->exists();

        if ($alreadyRecorded) {
            return;
        }

        $ticket = Ticket::find($this->ticketId);

        if ($ticket === null) {
            return;
        }

        $paymentMethod = strtolower(trim((string) $ticket->payment_method));
        $caisseCode = CaisseCode::forTicketPaymentMethod($paymentMethod);

        $amount = match ($caisseCode) {
            CaisseCode::TicketCash, CaisseCode::OrangeMoney => (int) $ticket->price,
            // TODO this must use the "received_amount" instead of calculating directly
            CaisseCode::Wave => (int) round($ticket->price / (1 + TicketManager::WAVE_FEES)),
            default => 0,
        };

        if ($caisseCode === null) {
            Log::warning("Unrecognized payment method '{$ticket->payment_method}'; skipping caisse deposit for ticket #{$ticket->id}.");

            return;
        }

        $caisse = Caisse::findByCode($caisseCode);

        if ($caisse === null) {
            Log::warning("Caisse with code {$caisseCode->value} is not configured; skipping deposit for ticket #{$ticket->id}.");

            return;
        }

        $label = $this->label ?? "Vente billet #{$ticket->number}";

        $accountService->depositToCaisseAndCreditAccount(
            caisse: $caisse,
            account: $accountService->getOrCreateTicketSalesAccount(),
            amount: $amount,
            caisseLabel: $label,
            accountLabel: $label,
            referenceType: $this->referenceType,
            referenceId: $ticket->id,
            userId: $this->userId,
            categoryOverride: AccountTransactionCategory::Revenue,
        );
    }
}
