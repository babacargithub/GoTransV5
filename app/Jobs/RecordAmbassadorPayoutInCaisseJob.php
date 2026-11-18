<?php

namespace App\Jobs;

use App\Models\AmbassadorPayout;
use App\Services\AmbassadorCommissionAccountingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Records the Wave caisse withdrawal and the commissions account debit of one settled payout. Queued, so an accounting problem can never block or delay the booking, the cancellation or the
 * payout it follows; idempotent, so a retry never records it twice.
 */
class RecordAmbassadorPayoutInCaisseJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(private readonly int $payoutId) {}

    public function handle(AmbassadorCommissionAccountingService $accounting): void
    {
        $record = AmbassadorPayout::find($this->payoutId);

        if ($record === null) {
            return;
        }

        $accounting->recordPayout($record);
    }
}
