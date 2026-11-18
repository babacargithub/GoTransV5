<?php

namespace App\Jobs;

use App\Models\AmbassadorEarning;
use App\Services\AmbassadorCommissionAccountingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Gives the commission of one cancelled earning back to TicketSales. Queued, so an accounting problem can never block or delay the booking, the cancellation or the
 * payout it follows; idempotent, so a retry never records it twice.
 */
class ReleaseAmbassadorCommissionReserveJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(private readonly int $earningId) {}

    public function handle(AmbassadorCommissionAccountingService $accounting): void
    {
        $record = AmbassadorEarning::find($this->earningId);

        if ($record === null) {
            return;
        }

        $accounting->releaseEarning($record);
    }
}
