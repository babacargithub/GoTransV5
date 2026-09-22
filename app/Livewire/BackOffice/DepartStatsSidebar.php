<?php

namespace App\Livewire\BackOffice;

use App\Http\Controllers\DepartController;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Booking statistics sidebar shown on every back-office page.
 *
 * Rebuilds the legacy Vue back-office sidebar: for each upcoming départ it lists
 * every bus with its réservations / sièges réservés / billets vendus counts. The
 * data comes straight from the untouched DepartController@bookingsCount (the same
 * method the legacy `departs/bookings_counts` endpoint serves).
 */
class DepartStatsSidebar extends Component
{
    /**
     * Upcoming départs and, per bus, their booking counts.
     *
     * @return array<int, array{depart: string, buses: array<int, array{id: int, name: string, bookingsCount: int, bookedSeatsCount: int, ticketsSoldCount: int, closed: bool, hasSeatsLeft: bool}>}>
     */
    #[Computed]
    public function departStatsRows(): array
    {
        $legacyRows = app(DepartController::class)->bookingsCount()->getData(true);

        return collect($legacyRows)
            ->map(fn (array $departRow): array => [
                'depart' => (string) $departRow['depart'],
                'buses' => collect($departRow['buses'] ?? [])
                    ->map(fn (array $busRow): array => [
                        'id' => (int) $busRow['id'],
                        'name' => (string) $busRow['name'],
                        'bookingsCount' => (int) $busRow['bookingsCount'],
                        'bookedSeatsCount' => (int) $busRow['bookedSeatsCount'],
                        'ticketsSoldCount' => (int) $busRow['ticketsSoldCount'],
                        'closed' => (bool) $busRow['closed'],
                        'hasSeatsLeft' => (bool) $busRow['hasSeatsLeft'],
                    ])
                    ->all(),
            ])
            ->all();
    }

    /**
     * Total réservations across every upcoming départ.
     */
    public function totalBookingsCount(): int
    {
        return collect($this->departStatsRows)
            ->flatMap(fn (array $departRow): array => $departRow['buses'])
            ->sum('bookingsCount');
    }

    public function refreshDepartStats(): void
    {
        unset($this->departStatsRows);
    }

    public function render(): View
    {
        return view('livewire.back-office.depart-stats-sidebar');
    }
}
