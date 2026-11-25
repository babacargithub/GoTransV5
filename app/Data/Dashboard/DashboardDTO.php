<?php

namespace App\Data\Dashboard;

use App\Data\Ambassador\AmbassadorBalanceDTO;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;

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
        /** @var array<string, int> bookings made per day, keyed by Y-m-d (days without any are absent) */
        public array $bookingsPerDay = [],
        /** @var array<string, int> new customers per day, keyed by Y-m-d (days without any are absent) */
        public array $newCustomersPerDay = [],
    ) {}

    /**
     * Every day of the period with its bookings and new customers (zero when none), ready to plot.
     *
     * @return list<array{date: string, bookings: int, newCustomers: int}>
     */
    public function dailyTrend(): array
    {
        $days = [];
        foreach (CarbonPeriod::create($this->periodStart->copy()->startOfDay(), $this->periodEnd->copy()->startOfDay()) as $day) {
            $date = $day->toDateString();
            $days[] = [
                'date' => $date,
                'bookings' => $this->bookingsPerDay[$date] ?? 0,
                'newCustomers' => $this->newCustomersPerDay[$date] ?? 0,
            ];
        }

        return $days;
    }

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
