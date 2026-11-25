<?php

namespace App\Data\Dashboard;

use App\Data\Ambassador\AmbassadorBalanceDTO;
use Carbon\CarbonInterface;

/**
 * The figures of the back-office dashboard for one period, optionally narrowed to a single trajet.
 * Every count comes from a dedicated service; this class only carries them and derives the percentages.
 */
readonly class DashboardDTO
{
    public function __construct(
        public CarbonInterface $periodStart,
        public CarbonInterface $periodEnd,
        public ?int $trajetId,
        public int $newCustomersCount,
        public int $newCustomersBroughtByAmbassadorsCount,
        public int $bookingsCount,
        public int $bookingsBroughtByAmbassadorsCount,
        public int $departsCount,
        public int $busesCount,
        public AmbassadorBalanceDTO $amountDueToAmbassadors,
    ) {}

    public function newCustomersBroughtByAmbassadorsPercentage(): float
    {
        return $this->percentageOf($this->newCustomersBroughtByAmbassadorsCount, $this->newCustomersCount);
    }

    public function bookingsBroughtByAmbassadorsPercentage(): float
    {
        return $this->percentageOf($this->bookingsBroughtByAmbassadorsCount, $this->bookingsCount);
    }

    private function percentageOf(int $part, int $whole): float
    {
        return $whole === 0 ? 0.0 : round($part / $whole * 100, 1);
    }
}
