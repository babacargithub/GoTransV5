<?php

namespace App\Livewire\BackOffice;

use App\Enums\PermissionName;
use App\Models\Account;
use App\Models\Booking;
use App\Models\Bus;
use App\Models\Caisse;
use App\Models\Depart;
use App\Models\DepartCostReport;
use App\Models\DepartCostReportBusRental;
use App\Services\DepartCostReportPaymentService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
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
 *
 * "Enregistrer plusieurs" switches the list to a selection mode where several départs can be paid
 * out of the caisses in one go; each départ is still recorded as its own operation by
 * DepartCostReportPaymentService.
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

    public bool $isSelecting = false;

    /**
     * Ids of the départs ticked for the bulk cash-out; kept across pages and filter changes.
     *
     * @var array<int, int|string>
     */
    public array $selectedDepartIds = [];

    public bool $showCashOutModal = false;

    /**
     * @var array<int, array{caisseId: string, amount: string}>
     */
    public array $cashOutCaisseRows = [];

    /**
     * @var array<int, array{accountId: string, amount: string}>
     */
    public array $cashOutAccountRows = [];

    public ?string $flashStatusMessage = null;

    public ?string $flashErrorMessage = null;

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

    public function startSelecting(): void
    {
        $this->flashStatusMessage = null;
        $this->flashErrorMessage = null;
        $this->isSelecting = true;
    }

    public function cancelSelecting(): void
    {
        $this->isSelecting = false;
        $this->selectedDepartIds = [];
        $this->showCashOutModal = false;
        unset($this->selectedDepartsToPay, $this->selectedTotalToPay);
    }

    public function updatedSelectedDepartIds(): void
    {
        unset($this->selectedDepartsToPay, $this->selectedTotalToPay);
    }

    /**
     * The ticked départs with what is still owed on each, ignoring any with nothing left to pay.
     *
     * @return Collection<int, array{departId: int, departName: string, remainingToPay: int}>
     */
    #[Computed]
    public function selectedDepartsToPay(): Collection
    {
        return DepartCostReport::query()
            ->whereIn('depart_id', array_map('intval', $this->selectedDepartIds))
            ->with(['depart', 'busRentals'])
            ->get()
            ->map(fn (DepartCostReport $costReport): array => [
                'departId' => $costReport->depart_id,
                'departName' => $costReport->depart->identifier(with_trajet_prefix: true),
                'remainingToPay' => (int) $costReport->busRentals->sum(fn (DepartCostReportBusRental $rental): int => $rental->remainingAmountToPay()),
            ])
            ->filter(fn (array $selectedDepart): bool => $selectedDepart['remainingToPay'] > 0)
            ->values();
    }

    #[Computed]
    public function selectedTotalToPay(): int
    {
        return (int) $this->selectedDepartsToPay->sum('remainingToPay');
    }

    /**
     * @return array<int, array{id: int, name: string, balance: int}>
     */
    #[Computed]
    public function cashOutCaisseOptions(): array
    {
        return Caisse::query()
            ->where('closed', false)
            ->orderBy('name')
            ->get()
            ->reject(fn (Caisse $caisse): bool => $caisse->isLockedForToday())
            ->map(fn (Caisse $caisse): array => ['id' => $caisse->id, 'name' => $caisse->name, 'balance' => $caisse->balance])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id: int, name: string, balance: int}>
     */
    #[Computed]
    public function cashOutAccountOptions(): array
    {
        return Account::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (Account $account): array => ['id' => $account->id, 'name' => $account->name, 'balance' => $account->balance])
            ->all();
    }

    public function openCashOutModal(): void
    {
        PermissionName::FullAccess->authorizeForCurrentUser();

        $this->flashErrorMessage = null;
        unset($this->selectedDepartsToPay, $this->selectedTotalToPay);

        if ($this->selectedTotalToPay <= 0) {
            $this->flashErrorMessage = 'Sélectionnez au moins un départ avec un reste à payer.';

            return;
        }

        $this->resetValidation();
        $this->cashOutCaisseRows = [['caisseId' => '', 'amount' => (string) $this->selectedTotalToPay]];
        $this->cashOutAccountRows = [['accountId' => '', 'amount' => (string) $this->selectedTotalToPay]];
        $this->showCashOutModal = true;
    }

    public function closeCashOutModal(): void
    {
        $this->showCashOutModal = false;
    }

    public function addCashOutCaisseRow(): void
    {
        $this->cashOutCaisseRows[] = ['caisseId' => '', 'amount' => ''];
        $this->fillSingleRowAmountsWithTotal();
    }

    public function removeCashOutCaisseRow(int $rowIndex): void
    {
        unset($this->cashOutCaisseRows[$rowIndex]);
        $this->cashOutCaisseRows = array_values($this->cashOutCaisseRows);
        $this->fillSingleRowAmountsWithTotal();
    }

    public function addCashOutAccountRow(): void
    {
        $this->cashOutAccountRows[] = ['accountId' => '', 'amount' => ''];
        $this->fillSingleRowAmountsWithTotal();
    }

    public function removeCashOutAccountRow(int $rowIndex): void
    {
        unset($this->cashOutAccountRows[$rowIndex]);
        $this->cashOutAccountRows = array_values($this->cashOutAccountRows);
        $this->fillSingleRowAmountsWithTotal();
    }

    /**
     * Money-out: full-access only, re-validated server side. DepartCostReportPaymentService re-reads every
     * bilan under a lock and records one caisse/account operation per départ, all or nothing.
     */
    public function saveCashOut(DepartCostReportPaymentService $departCostReportPaymentService): void
    {
        PermissionName::FullAccess->authorizeForCurrentUser();

        $this->flashStatusMessage = null;
        $this->flashErrorMessage = null;

        $this->fillSingleRowAmountsWithTotal();
        $totalToPay = $this->selectedTotalToPay;

        $this->validate([
            'cashOutCaisseRows' => ['required', 'array', 'min:1'],
            'cashOutCaisseRows.*.caisseId' => ['required', 'integer', 'distinct', 'exists:caisses,id'],
            'cashOutCaisseRows.*.amount' => ['required', 'integer', 'min:1'],
            'cashOutAccountRows' => ['required', 'array', 'min:1'],
            'cashOutAccountRows.*.accountId' => ['required', 'integer', 'distinct', 'exists:accounts,id'],
            'cashOutAccountRows.*.amount' => ['required', 'integer', 'min:1'],
        ], [], [
            'cashOutCaisseRows.*.caisseId' => 'caisse',
            'cashOutCaisseRows.*.amount' => 'montant de la caisse',
            'cashOutAccountRows.*.accountId' => 'compte',
            'cashOutAccountRows.*.amount' => 'montant du compte',
        ]);

        $amountByCaisseId = collect($this->cashOutCaisseRows)
            ->mapWithKeys(fn (array $row): array => [(int) $row['caisseId'] => (int) $row['amount']])->all();
        $amountByAccountId = collect($this->cashOutAccountRows)
            ->mapWithKeys(fn (array $row): array => [(int) $row['accountId'] => (int) $row['amount']])->all();

        if (array_sum($amountByCaisseId) !== $totalToPay) {
            $this->addError('cashOutCaisseRows', "Le total des caisses doit être égal au total à payer ({$totalToPay} F).");

            return;
        }

        if (array_sum($amountByAccountId) !== $totalToPay) {
            $this->addError('cashOutAccountRows', "Le total des comptes doit être égal au total à payer ({$totalToPay} F).");

            return;
        }

        $departIdsToPay = $this->selectedDepartsToPay->pluck('departId')->map(fn ($departId): int => (int) $departId)->all();

        try {
            $paidAmountByDepartId = $departCostReportPaymentService->recordBulkBusRentalPayments(
                $departIdsToPay,
                $amountByCaisseId,
                $amountByAccountId,
                auth()->id(),
            );
        } catch (\Throwable $exception) {
            $this->flashErrorMessage = $exception->getMessage();

            return;
        }

        $this->cancelSelecting();
        unset($this->costReportsPage, $this->costReportRows, $this->cashOutCaisseOptions, $this->cashOutAccountOptions);
        $this->flashStatusMessage = count($paidAmountByDepartId).' sorties enregistrées en caisse pour un total de '.array_sum($paidAmountByDepartId).' F.';
    }

    /**
     * With a single caisse (or account) row, its amount is by definition the total to pay.
     */
    private function fillSingleRowAmountsWithTotal(): void
    {
        $totalAsString = (string) $this->selectedTotalToPay;

        if (count($this->cashOutCaisseRows) === 1) {
            $this->cashOutCaisseRows[0]['amount'] = $totalAsString;
        }

        if (count($this->cashOutAccountRows) === 1) {
            $this->cashOutAccountRows[0]['amount'] = $totalAsString;
        }
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
            ->withSum('busRentals as total_paid_amount', 'paid_amount')
            ->orderByDesc(
                Depart::query()->select('date')->whereColumn('departs.id', 'depart_cost_reports.depart_id')->limit(1)
            )
            ->orderByDesc('id')
            ->paginate((int) config('app.back_office_departs_per_page'));
    }

    /**
     * One display row per cost report of the current page.
     *
     * @return array<int, array{departId: int, departName: string, departDate: string, busesCount: int, ticketSales: int, busRentalFees: int, remainingToPay: int, busRentalPaymentStatus: 'none'|'unpaid'|'partial'|'paid', commissions: int, profit: int}>
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
                $paidAmount = (int) $costReport->total_paid_amount;

                return [
                    'departId' => $costReport->depart_id,
                    'departName' => $costReport->depart->identifier(with_trajet_prefix: true),
                    'departDate' => $costReport->depart->date->isoFormat('ddd D MMM YYYY'),
                    'busesCount' => (int) ($busesCountByDepartId[$costReport->depart_id] ?? 0),
                    'ticketSales' => $ticketSales,
                    'busRentalFees' => $busRentalFees,
                    'remainingToPay' => max(0, $busRentalFees - $paidAmount),
                    'busRentalPaymentStatus' => match (true) {
                        $busRentalFees === 0 => 'none',
                        $paidAmount >= $busRentalFees => 'paid',
                        $paidAmount > 0 => 'partial',
                        default => 'unpaid',
                    },
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
