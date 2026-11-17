<?php

namespace App\Console\Commands;

use App\Services\WaveService;
use Illuminate\Console\Command;

class RetryUnsettledAmbassadorPayouts extends Command
{
    protected $signature = 'ambassador:retry-unsettled-payouts';

    protected $description = 'Send again the ambassador payouts that failed or were never sent and still have attempts left';

    public function handle(WaveService $waveService): int
    {
        $retriedPayoutsCount = $waveService->retryUnsettledPayouts();

        $this->info("{$retriedPayoutsCount} payout(s) retried.");

        return self::SUCCESS;
    }
}
