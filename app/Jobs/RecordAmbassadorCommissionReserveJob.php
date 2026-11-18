<?php

namespace App\Jobs;

use App\Models\AmbassadorEarning;
use App\Services\AmbassadorCommissionAccountingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sets aside the commission of one earning (TicketSales -> Commissions ambassadeurs). Queued, so an
 * accounting problem can never block or delay the earning it follows; idempotent, so a retry never records
 * it twice. Retries are spread out because the usual cause of a failure (TicketSales not credited yet)
 * clears by itself.
 */
class RecordAmbassadorCommissionReserveJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    /**
     * @var array<int, int> seconds to wait before each retry
     */
    public array $backoff = [60, 300, 900, 3600, 10800];

    public function __construct(private readonly int $earningId) {}

    public function handle(AmbassadorCommissionAccountingService $accounting): void
    {
        $record = AmbassadorEarning::find($this->earningId);

        if ($record === null) {
            return;
        }

        $accounting->reserveEarning($record);
    }
}
