<?php

namespace App\Livewire\BackOffice;

use App\Http\Controllers\BusController;
use App\Http\Controllers\DepartController;
use App\Http\Resources\DepartResource;
use App\Models\Bus;
use App\Models\Depart;
use App\Services\BusService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Back office départ list.
 *
 * Was a plain Blade page; became a full-page Livewire component once it needed
 * three in-place dialogs on the same page (ventes de billets, répartition des
 * clients, and — via a plain download link — l'export des réservations). Every
 * dialog reuses an untouched DepartController method and only reads its JSON.
 * The legacy JSON API keeps hitting DepartController@index unchanged.
 */
#[Layout('components.layouts.back-office')]
class DepartList extends Component
{
    public bool $showTicketSalesModal = false;

    public ?int $ticketSalesDepartId = null;

    public bool $showBookingsRepartitionModal = false;

    public ?int $bookingsRepartitionDepartId = null;

    /**
     * When set, the répartition des clients dialog is scoped to a single bus of
     * the départ instead of counting every booking of the départ.
     */
    public ?int $bookingsRepartitionBusId = null;

    public bool $showBusTicketSalesModal = false;

    public ?int $busTicketSalesBusId = null;

    public bool $showBusSeatsModal = false;

    public ?int $busSeatsBusId = null;

    /**
     * Bus seat ids currently selected in the "Gestion des sièges" grid.
     *
     * @var array<int, int>
     */
    public array $selectedBusSeatIds = [];

    public ?string $busSeatsFlashMessage = null;

    public bool $showScheduleManagementModal = false;

    public ?int $scheduleManagementDepartId = null;

    /**
     * Which set of rendez-vous is being edited: 'depart' for the départ-wide
     * schedules, or 'bus:{id}' for a single bus of that départ.
     */
    public string $scheduleManagementScope = 'depart';

    /**
     * Editable rendez-vous rows for the selected scope.
     *
     * @var array<int, array{id: int, pointDepName: string, rendezVousPoint: string, rendezVousSchedule: string, isActive: bool}>
     */
    public array $scheduleManagementRows = [];

    public ?string $scheduleManagementFlashMessage = null;

    public bool $showCancelDepartModal = false;

    public ?int $cancelDepartId = null;

    /**
     * Upcoming départs rendered through the same resource the legacy API uses.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function departRows(): array
    {
        $upcomingDeparts = Depart::query()
            ->where('date', '>', now())
            ->with(['trajet', 'buses'])
            ->get();

        return DepartResource::collection($upcomingDeparts)->resolve(request());
    }

    public function openTicketSales(int $departId): void
    {
        $this->ticketSalesDepartId = $departId;
        $this->showTicketSalesModal = true;
    }

    public function closeTicketSales(): void
    {
        $this->showTicketSalesModal = false;
        $this->ticketSalesDepartId = null;
    }

    public function openBookingsRepartition(int $departId, ?int $busId = null): void
    {
        $this->bookingsRepartitionDepartId = $departId;
        $this->bookingsRepartitionBusId = $busId;
        $this->showBookingsRepartitionModal = true;
    }

    public function closeBookingsRepartition(): void
    {
        $this->showBookingsRepartitionModal = false;
        $this->bookingsRepartitionDepartId = null;
        $this->bookingsRepartitionBusId = null;
    }

    /**
     * Open or close a bus's bookings through BusController@toggleClose (the legacy
     * "Clôturer réservations" switch); the list re-renders with the new state.
     */
    public function toggleBusClosed(int $busId): void
    {
        $bus = Bus::findOrFail($busId);

        request()->merge(['closed' => ! $bus->closed]);

        try {
            app(BusController::class)->toggleClose($bus, request());
        } catch (\Throwable $exception) {
            session()->flash('error', $exception->getMessage());

            return;
        }

        unset($this->departRows);

        session()->flash('status', $bus->fresh()->closed
            ? 'Les réservations du bus '.$bus->name.' ont été clôturées.'
            : 'Les réservations du bus '.$bus->name.' ont été réouvertes.');
    }

    public function openBusTicketSales(int $busId): void
    {
        $this->busTicketSalesBusId = $busId;
        $this->showBusTicketSalesModal = true;
    }

    public function openBusSeats(int $busId): void
    {
        $this->busSeatsBusId = $busId;
        $this->selectedBusSeatIds = [];
        $this->busSeatsFlashMessage = null;
        $this->showBusSeatsModal = true;
    }

    public function closeBusSeats(): void
    {
        $this->showBusSeatsModal = false;
        $this->busSeatsBusId = null;
        $this->selectedBusSeatIds = [];
        $this->busSeatsFlashMessage = null;
    }

    public function busSeatsBusLabel(): ?string
    {
        if ($this->busSeatsBusId === null) {
            return null;
        }

        return Bus::findOrFail($this->busSeatsBusId)->full_name;
    }

    public function toggleBusSeatSelection(int $seatId): void
    {
        if (in_array($seatId, $this->selectedBusSeatIds, true)) {
            $this->selectedBusSeatIds = array_values(array_diff($this->selectedBusSeatIds, [$seatId]));

            return;
        }

        $this->selectedBusSeatIds[] = $seatId;
    }

    public function clearBusSeatSelection(): void
    {
        $this->selectedBusSeatIds = [];
    }

    /**
     * Editable seat rows for the "Gestion des sièges" grid, straight from
     * BusController@seatsForAdmin.
     *
     * @return array<int, array{id: int, name: string, booked: bool, locked: bool}>
     */
    #[Computed]
    public function busSeatRows(): array
    {
        if ($this->busSeatsBusId === null) {
            return [];
        }

        $bus = Bus::findOrFail($this->busSeatsBusId);

        return collect(app(BusController::class)->seatsForAdmin($bus))
            ->map(fn (array $row): array => [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'booked' => (bool) $row['booked'],
                'locked' => (bool) $row['locked'],
            ])
            ->all();
    }

    /**
     * @return array{free: int, booked: int, locked: int, total: int}
     */
    public function busSeatStatistics(): array
    {
        $rows = collect($this->busSeatRows);

        return [
            'free' => $rows->filter(fn (array $row): bool => ! $row['booked'] && ! $row['locked'])->count(),
            'booked' => $rows->where('booked', true)->count(),
            'locked' => $rows->where('locked', true)->count(),
            'total' => $rows->count(),
        ];
    }

    /**
     * Apply a bulk action (lock / unlock / book / unbook) to the selected seats
     * through BusController@performBulkAction (the legacy bulk-action endpoint).
     */
    public function performBusSeatsBulkAction(string $busSeatsBulkAction): void
    {
        if ($this->busSeatsBusId === null || $this->selectedBusSeatIds === []) {
            return;
        }

        $bus = Bus::findOrFail($this->busSeatsBusId);

        request()->merge(['seat_ids' => $this->selectedBusSeatIds]);
        request()->query->set('action', $busSeatsBulkAction);

        $bulkActionResponse = app(BusController::class)->performBulkAction($bus, request());
        $bulkActionPayload = $bulkActionResponse->getData(true);

        $this->busSeatsFlashMessage = $bulkActionPayload['message'] ?? 'Action effectuée.';
        $this->selectedBusSeatIds = [];
        unset($this->busSeatRows, $this->departRows);
    }

    /**
     * Free seats stuck as booked without an active booking, through
     * BusController@freeSeatsOfBus (the legacy "Libérer les sièges bloqués").
     */
    public function freeStuckBusSeats(): void
    {
        if ($this->busSeatsBusId === null) {
            return;
        }

        $bus = Bus::findOrFail($this->busSeatsBusId);

        $freeSeatsResponse = app(BusController::class)->freeSeatsOfBus($bus, app(BusService::class));

        $this->busSeatsFlashMessage = $freeSeatsResponse->getData(true)['message'] ?? 'Sièges libérés.';
        $this->selectedBusSeatIds = [];
        unset($this->busSeatRows, $this->departRows);
    }

    public function closeBusTicketSales(): void
    {
        $this->showBusTicketSalesModal = false;
        $this->busTicketSalesBusId = null;
    }

    public function busTicketSalesBusLabel(): ?string
    {
        if ($this->busTicketSalesBusId === null) {
            return null;
        }

        return Bus::findOrFail($this->busTicketSalesBusId)->full_name;
    }

    /**
     * Ticket sales of the selected bus grouped by "vendu par", straight from
     * BusController@busTicketSales.
     *
     * @return array<int, array{soldBy: string|null, total: float}>
     */
    #[Computed]
    public function busTicketSalesRows(): array
    {
        if ($this->busTicketSalesBusId === null) {
            return [];
        }

        $bus = Bus::findOrFail($this->busTicketSalesBusId);

        return collect(app(BusController::class)->busTicketSales($bus)->getData(true))
            ->map(fn (array $row): array => [
                'soldBy' => $row['soldBy'] ?? null,
                'total' => (float) ($row['total'] ?? 0),
            ])
            ->all();
    }

    public function busTicketSalesTotal(): float
    {
        return collect($this->busTicketSalesRows)->sum('total');
    }

    public function askToCancelDepart(int $departId): void
    {
        $this->cancelDepartId = $departId;
        $this->showCancelDepartModal = true;
    }

    public function closeCancelDepartModal(): void
    {
        $this->showCancelDepartModal = false;
        $this->cancelDepartId = null;
    }

    public function cancelDepartLabel(): ?string
    {
        if ($this->cancelDepartId === null) {
            return null;
        }

        return Depart::findOrFail($this->cancelDepartId)->identifier(with_trajet_prefix: true);
    }

    /**
     * Cancel the pending départ through DepartController@cancelDepart (soft cancel
     * when it has bookings, hard delete otherwise); the legacy JSON path is unchanged.
     */
    public function confirmCancelDepart(): void
    {
        $departId = $this->cancelDepartId;

        $this->closeCancelDepartModal();

        if ($departId === null) {
            return;
        }

        $depart = Depart::findOrFail($departId);
        $departLabel = $depart->identifier(with_trajet_prefix: true);

        try {
            app(DepartController::class)->cancelDepart($depart);
            session()->flash('status', 'Le départ '.$departLabel.' a été annulé.');
        } catch (\Throwable $exception) {
            session()->flash('error', $exception->getMessage());
        }

        $this->redirectRoute('back-office.departs.index', navigate: true);
    }

    public function openScheduleManagement(int $departId, ?int $busId = null): void
    {
        $this->scheduleManagementDepartId = $departId;
        $this->scheduleManagementScope = $busId !== null
            ? 'bus:'.$busId
            : $this->defaultScheduleManagementScope();
        $this->scheduleManagementFlashMessage = null;
        $this->resetValidation();
        $this->loadScheduleManagementRows();
        $this->showScheduleManagementModal = true;
    }

    public function closeScheduleManagement(): void
    {
        $this->showScheduleManagementModal = false;
        $this->scheduleManagementDepartId = null;
        $this->scheduleManagementScope = 'depart';
        $this->scheduleManagementRows = [];
        $this->scheduleManagementFlashMessage = null;
        $this->resetValidation();
    }

    public function updatedScheduleManagementScope(): void
    {
        $this->scheduleManagementFlashMessage = null;
        $this->resetValidation();
        $this->loadScheduleManagementRows();
    }

    /**
     * Buses of the départ whose rendez-vous can be managed, for the scope picker.
     *
     * @return array<int, array{id: int, name: string}>
     */
    #[Computed]
    public function scheduleManagementBuses(): array
    {
        if ($this->scheduleManagementDepartId === null) {
            return [];
        }

        return Depart::findOrFail($this->scheduleManagementDepartId)
            ->buses()
            ->get()
            ->map(fn (Bus $bus): array => ['id' => $bus->id, 'name' => $bus->name])
            ->all();
    }

    public function scheduleManagementDepartLabel(): ?string
    {
        if ($this->scheduleManagementDepartId === null) {
            return null;
        }

        return Depart::findOrFail($this->scheduleManagementDepartId)->identifier(with_trajet_prefix: true);
    }

    /**
     * Reload the editable rendez-vous rows for the current scope, straight from
     * DepartController@busStopSchedules (the legacy JSON path).
     */
    public function loadScheduleManagementRows(): void
    {
        if ($this->scheduleManagementDepartId === null) {
            $this->scheduleManagementRows = [];

            return;
        }

        $depart = Depart::findOrFail($this->scheduleManagementDepartId);

        request()->merge(['bus_id' => $this->selectedScheduleManagementBusId()]);

        $busStopSchedules = app(DepartController::class)
            ->busStopSchedules($depart, request())
            ->getData(true);

        $this->scheduleManagementRows = collect($busStopSchedules)
            ->map(fn (array $busStopSchedule): array => [
                'id' => (int) $busStopSchedule['id'],
                'pointDepName' => (string) $busStopSchedule['pointDep'],
                'rendezVousPoint' => (string) ($busStopSchedule['rendezVousPoint'] ?? ''),
                'rendezVousSchedule' => (string) $busStopSchedule['rendezVousSchedule'],
                'isActive' => ! (bool) $busStopSchedule['disabled'],
            ])
            ->all();
    }

    /**
     * Persist every rendez-vous row through DepartController@updateBusStopSchedules.
     */
    public function saveScheduleManagementRows(): void
    {
        if ($this->scheduleManagementDepartId === null || $this->scheduleManagementRows === []) {
            return;
        }

        $this->validate([
            'scheduleManagementRows.*.rendezVousPoint' => ['required', 'string', 'max:100'],
            'scheduleManagementRows.*.rendezVousSchedule' => ['required', 'date_format:H:i'],
        ], [
            'scheduleManagementRows.*.rendezVousPoint.required' => 'Le point de rendez-vous est obligatoire.',
            'scheduleManagementRows.*.rendezVousPoint.max' => 'Le point de rendez-vous ne peut pas dépasser 100 caractères.',
            'scheduleManagementRows.*.rendezVousSchedule.required' => "L'heure de rendez-vous est obligatoire.",
            'scheduleManagementRows.*.rendezVousSchedule.date_format' => "L'heure de rendez-vous doit être au format HH:MM.",
        ]);

        $depart = Depart::findOrFail($this->scheduleManagementDepartId);

        $busStopSchedules = collect($this->scheduleManagementRows)
            ->map(fn (array $row): array => [
                'id' => $row['id'],
                'pointDep' => $row['pointDepName'],
                'rendezVousPoint' => $row['rendezVousPoint'],
                'rendezVousSchedule' => $row['rendezVousSchedule'],
                'disabled' => ! $row['isActive'],
            ])
            ->all();

        request()->merge(['busStopSchedules' => $busStopSchedules]);

        app(DepartController::class)->updateBusStopSchedules($depart, request());

        $this->scheduleManagementFlashMessage = 'Les rendez-vous ont été enregistrés.';
        $this->loadScheduleManagementRows();
    }

    /**
     * Create a rendez-vous row for every point de départ of the trajet that the
     * selected bus does not have yet, through DepartController@addPointDepsSchedulesForBus
     * (the legacy "Ajouter tous les arrêts" button). Only available in a bus scope.
     */
    public function addAllBusStopSchedules(): void
    {
        $busId = $this->selectedScheduleManagementBusId();

        if ($busId === null) {
            return;
        }

        try {
            app(DepartController::class)->addPointDepsSchedulesForBus(Bus::findOrFail($busId));
            $this->scheduleManagementFlashMessage = 'Les arrêts ont été ajoutés.';
        } catch (\Throwable $exception) {
            $this->scheduleManagementFlashMessage = $exception->getMessage();
        }

        $this->loadScheduleManagementRows();
    }

    public function scheduleManagementScopeIsBus(): bool
    {
        return $this->selectedScheduleManagementBusId() !== null;
    }

    /**
     * Open on the first bus of the départ when it has any (that is where the
     * rendez-vous actually live nowadays); fall back to the départ-wide scope.
     */
    private function defaultScheduleManagementScope(): string
    {
        $buses = $this->scheduleManagementBuses();

        return $buses === [] ? 'depart' : 'bus:'.$buses[0]['id'];
    }

    private function selectedScheduleManagementBusId(): ?int
    {
        if (! str_starts_with($this->scheduleManagementScope, 'bus:')) {
            return null;
        }

        return (int) substr($this->scheduleManagementScope, strlen('bus:'));
    }

    public function ticketSalesDepartLabel(): ?string
    {
        if ($this->ticketSalesDepartId === null) {
            return null;
        }

        return Depart::findOrFail($this->ticketSalesDepartId)->identifier(with_trajet_prefix: true);
    }

    public function bookingsRepartitionDepartLabel(): ?string
    {
        if ($this->bookingsRepartitionDepartId === null) {
            return null;
        }

        $label = Depart::findOrFail($this->bookingsRepartitionDepartId)->identifier(with_trajet_prefix: true);

        if ($this->bookingsRepartitionBusId !== null) {
            $label .= ' — '.Bus::findOrFail($this->bookingsRepartitionBusId)->name;
        }

        return $label;
    }

    /**
     * Ticket sales of the selected départ grouped by "vendu par", straight from
     * DepartController@ticketSales.
     *
     * @return array<int, array{soldBy: string|null, total: float}>
     */
    #[Computed]
    public function ticketSalesRows(): array
    {
        if ($this->ticketSalesDepartId === null) {
            return [];
        }

        $depart = Depart::findOrFail($this->ticketSalesDepartId);

        return collect(app(DepartController::class)->ticketSales($depart)->getData(true))
            ->map(fn (array $row): array => [
                'soldBy' => $row['soldBy'] ?? null,
                'total' => (float) ($row['total'] ?? 0),
            ])
            ->all();
    }

    public function ticketSalesTotal(): float
    {
        return collect($this->ticketSalesRows)->sum('total');
    }

    /**
     * Paid-booking count per point de départ for the selected départ, straight
     * from DepartController@bookingGroupingsCount.
     *
     * @return array<int, array{name: string, bookingsCount: int}>
     */
    #[Computed]
    public function bookingsRepartitionRows(): array
    {
        if ($this->bookingsRepartitionDepartId === null) {
            return [];
        }

        $depart = Depart::findOrFail($this->bookingsRepartitionDepartId);

        if ($this->bookingsRepartitionBusId !== null) {
            request()->merge(['bus_id' => $this->bookingsRepartitionBusId]);
        }

        return collect(app(DepartController::class)->bookingGroupingsCount($depart, request())->getData(true))
            ->map(fn (array $row): array => [
                'name' => $row['name'],
                'bookingsCount' => (int) $row['bookingsCount'],
            ])
            ->all();
    }

    public function bookingsRepartitionTotal(): int
    {
        return collect($this->bookingsRepartitionRows)->sum('bookingsCount');
    }

    public function render(): View
    {
        return view('livewire.back-office.depart-list')->title('Départs — Back Office');
    }
}
