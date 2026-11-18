<?php

namespace App\Jobs;

use App\Models\AmbassadorPayout;
use App\Services\AmbassadorCommissionAccountingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Records the Wave caisse withdrawal and the commissions account debit of one settled payout. Queued, so an
 * accounting problem can never block or delay the payout it follows; idempotent, so a retry never records
 * it twice. Retries are spread out because the usual cause of a failure (TicketSales not credited yet)
 * clears by itself.
 */
class RecordAmbassadorPayoutInCaisseJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    /**
     * @var array<int, int> seconds to wait before each retry
     */
    public array $backoff = [60, 300, 900, 3600, 10800];

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
