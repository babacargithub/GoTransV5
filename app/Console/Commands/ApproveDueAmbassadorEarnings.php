<?php

namespace App\Console\Commands;

use App\Services\AmbassadorEarningService;
use Illuminate\Console\Command;

class ApproveDueAmbassadorEarnings extends Command
{
    protected $signature = 'ambassador:approve-due-earnings';

    protected $description = 'Approve pending ambassador earnings whose waiting period is over';

    public function handle(AmbassadorEarningService $ambassadorEarningLifecycleService): int
    {
        $approvedEarningsCount = $ambassadorEarningLifecycleService->approveDueEarnings();

        $this->info("{$approvedEarningsCount} earning(s) approved.");

        return self::SUCCESS;
    }
}
