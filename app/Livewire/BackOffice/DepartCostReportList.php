<?php

namespace App\Livewire\BackOffice;

use App\Enums\PermissionName;
use App\Models\Booking;
use App\Models\Bus;
use App\Models\Depart;
use App\Models\DepartCostReport;
use App\Models\DepartCostReportBusRental;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Back office "Bilans" page (Finances menu): one row per départ that has a saved cost report, with
 * its ticket sales, bus rental fees, commissions and profit, under a card summarising them all.
 *
 * Shows company-wide sales and profit, so it is restricted to full-access users (the same rule that
 * hides these figures on the cost report page itself).
 */
#[Layout('components.layouts.back-office')]
class DepartCostReportList extends Component
{
    use WithPagination;

    /**
     * TODO: commissions are not implemented yet; every départ counts 0 until they are.
     */
    private const COMMISSIONS_PLACEHOLDER = 0;

    /**
     * Inclusive départ date range (Y-m-d) the list and the summary are narrowed to; '' means unbounded.
     * Defaults to the current month when the page is opened without a range in the URL.
     */
    #[Url(as: 'du')]
    public string $startDate = '';

    #[Url(as: 'au')]
    public string $endDate = '';

    public bool $showFilters = false;

    public function mount(): void
    {
        PermissionName::FullAccess->authorizeForCurrentUser();

        if ($this->startDate === '' && $this->endDate === '') {
            $this->applyCurrentMonthRange();
        }
    }

    public function toggleFilters(): void
    {
        $this->showFilters = ! $this->showFilters;
    }

    public function updatedStartDate(): void
    {
        $this->resetPage();
    }

    public function updatedEndDate(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->applyCurrentMonthRange();
        $this->resetPage();
    }

    /**
     * Whether the range differs from the default (current month).
     */
    public function hasActiveFilters(): bool
    {
        return $this->startDate !== now()->startOfMonth()->toDateString()
            || $this->endDate !== now()->endOfMonth()->toDateString();
    }

    private function applyCurrentMonthRange(): void
    {
        $this->startDate = now()->startOfMonth()->toDateString();
        $this->endDate = now()->endOfMonth()->toDateString();
    }

    /**
     * Cost reports of départs that still exist (a cancelled départ is hidden by Depart's global scope)
     * and whose date falls in the selected range.
     *
     * @return Builder<DepartCostReport>
     */
    private function costReportsOfExistingDeparts(): Builder
    {
        return DepartCostReport::query()->whereHas('depart', function (Builder $departs): void {
            $departs
                ->when($this->startDate !== '', fn (Builder $query) => $query->whereDate('date', '>=', $this->startDate))
                ->when($this->endDate !== '', fn (Builder $query) => $query->whereDate('date', '<=', $this->endDate));
        });
    }

    /**
     * @return LengthAwarePaginator<int, DepartCostReport>
     */
    #[Computed]
    public function costReportsPage(): LengthAwarePaginator
    {
        return $this->costReportsOfExistingDeparts()
            ->with('depart.trajet')
            ->withSum('busRentals as total_rental_fees', 'rental_fee')
            ->orderByDesc(
                Depart::query()->select('date')->whereColumn('departs.id', 'depart_cost_reports.depart_id')->limit(1)
            )
            ->orderByDesc('id')
            ->paginate((int) config('app.back_office_departs_per_page'));
    }

    /**
     * One display row per cost report of the current page.
     *
     * @return array<int, array{departId: int, departName: string, departDate: string, busesCount: int, ticketSales: int, busRentalFees: int, commissions: int, profit: int}>
     */
    #[Computed]
    public function costReportRows(): array
    {
        $departIds = $this->costReportsPage->getCollection()->pluck('depart_id')->all();

        $busesCountByDepartId = Bus::query()
            ->whereIn('depart_id', $departIds)
            ->selectRaw('depart_id, COUNT(*) as buses_count')
            ->groupBy('depart_id')
            ->pluck('buses_count', 'depart_id');

        $ticketSalesByDepartId = $this->ticketSalesQuery($departIds)
            ->selectRaw('bookings.depart_id as depart_id, SUM(tickets.price) as total')
            ->groupBy('bookings.depart_id')
            ->pluck('total', 'depart_id');

        return $this->costReportsPage->getCollection()
            ->map(function (DepartCostReport $costReport) use ($busesCountByDepartId, $ticketSalesByDepartId): array {
                $ticketSales = (int) ($ticketSalesByDepartId[$costReport->depart_id] ?? 0);
                $busRentalFees = (int) $costReport->total_rental_fees;
                $commissions = self::COMMISSIONS_PLACEHOLDER;

                return [
                    'departId' => $costReport->depart_id,
                    'departName' => $costReport->depart->identifier(with_trajet_prefix: true),
                    'departDate' => $costReport->depart->date->isoFormat('ddd D MMM YYYY'),
                    'busesCount' => (int) ($busesCountByDepartId[$costReport->depart_id] ?? 0),
                    'ticketSales' => $ticketSales,
                    'busRentalFees' => $busRentalFees,
                    'commissions' => $commissions,
                    'profit' => $ticketSales - $busRentalFees - $commissions,
                ];
            })
            ->all();
    }

    /**
     * Totals over every cost report (not only the current page).
     *
     * @return array{departsCount: int, busesCount: int, ticketSales: int, commissions: int, expenses: int, netProfit: int}
     */
    #[Computed]
    public function summary(): array
    {
        $costReportIds = $this->costReportsOfExistingDeparts()->pluck('id');
        $departIds = $this->costReportsOfExistingDeparts()->pluck('depart_id')->all();

        $ticketSales = (int) $this->ticketSalesQuery($departIds)->sum('tickets.price');
        $busRentalFees = (int) DepartCostReportBusRental::query()
            ->whereIn('depart_cost_report_id', $costReportIds)
            ->sum('rental_fee');
        $commissions = self::COMMISSIONS_PLACEHOLDER * count($departIds);

        // TODO: expenses will include more than bus rentals later; for now they are the rental fees plus commissions.
        $expenses = $busRentalFees + $commissions;

        return [
            'departsCount' => count($departIds),
            'busesCount' => Bus::query()->whereIn('depart_id', $departIds)->count(),
            'ticketSales' => $ticketSales,
            'commissions' => $commissions,
            'expenses' => $expenses,
            'netProfit' => $ticketSales - $expenses,
        ];
    }

    /**
     * Bookings holding a ticket, joined to their ticket, for the given départs — the same
     * "ticket sales" definition as DepartController@ticketSales.
     *
     * @param  array<int, int>  $departIds
     * @return Builder<Booking>
     */
    private function ticketSalesQuery(array $departIds): Builder
    {
        return Booking::query()
            ->whereIn('bookings.depart_id', $departIds)
            ->whereNotNull('bookings.ticket_id')
            ->join('tickets', 'bookings.ticket_id', '=', 'tickets.id');
    }

    public function render(): View
    {
        return view('livewire.back-office.depart-cost-report-list')->title('Bilans');
    }
}
