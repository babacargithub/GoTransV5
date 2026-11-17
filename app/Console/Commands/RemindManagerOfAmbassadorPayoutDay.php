<?php

namespace App\Console\Commands;

use App\Services\AmbassadorPayoutRunService;
use App\Services\NotificationService;
use Illuminate\Console\Command;

class RemindManagerOfAmbassadorPayoutDay extends Command
{
    protected $signature = 'ambassador:remind-manager-of-payout-day';

    protected $description = 'On the 15th and the last day of the month, text the manager how many ambassadors are due and the total to pay';

    public function handle(AmbassadorPayoutRunService $ambassadorPayoutRunService, NotificationService $notificationService): int
    {
        if (! $ambassadorPayoutRunService->isPayoutDay(now())) {
            $this->info('Not a payout day: nothing sent.');

            return self::SUCCESS;
        }

        $summary = $ambassadorPayoutRunService->summarizePayableAmounts();

        if ($summary['ambassadors_count'] === 0) {
            $this->info('Payout day, but no ambassador is due: nothing sent.');

            return self::SUCCESS;
        }

        $notificationService->notifyManagerOfAmbassadorPayoutDay($summary['ambassadors_count'], $summary['total_amount']);
        $this->info("Manager reminded: {$summary['ambassadors_count']} ambassador(s), {$summary['total_amount']} FCFA.");

        return self::SUCCESS;
    }
}
