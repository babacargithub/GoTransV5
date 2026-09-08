<?php

namespace App\Livewire\BackOffice;

use App\Http\Controllers\DepartController;
use App\Http\Resources\DepartResource;
use App\Models\Depart;
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

    public function openBookingsRepartition(int $departId): void
    {
        $this->bookingsRepartitionDepartId = $departId;
        $this->showBookingsRepartitionModal = true;
    }

    public function closeBookingsRepartition(): void
    {
        $this->showBookingsRepartitionModal = false;
        $this->bookingsRepartitionDepartId = null;
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

        return Depart::findOrFail($this->bookingsRepartitionDepartId)->identifier(with_trajet_prefix: true);
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
