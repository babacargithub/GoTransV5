<?php

namespace App\Console\Commands;

use App\Services\AmbassadorCommissionAccountingService;
use Illuminate\Console\Command;

class RecordMissingAmbassadorCommissionEntriesCommand extends Command
{
    protected $signature = 'ambassador:record-missing-commission-entries';

    protected $description = 'Set aside the ambassador commissions in the Commissions ambassadeurs account and record settled payouts, for lines that are not recorded yet';

    public function handle(AmbassadorCommissionAccountingService $accounting): int
    {
        $outcome = $accounting->recordMissingEntries();

        $this->info("{$outcome['reserved']} commission(s) set aside, {$outcome['released']} released, {$outcome['payouts_recorded']} payout(s) recorded, {$outcome['failed']} failed.");

        return $outcome['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
