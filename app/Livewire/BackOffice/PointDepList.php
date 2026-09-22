<?php

namespace App\Livewire\BackOffice;

use App\Http\Controllers\PointDepController;
use App\Models\PointDep;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Back office "Points de départ" list.
 *
 * Full-page Livewire component: it lists every point de départ (with its trajet)
 * and edits / disables / deletes them in place. Every mutation reuses an
 * untouched PointDepController method through the shared controller — the legacy
 * JSON API keeps hitting the same methods unchanged.
 */
#[Layout('components.layouts.back-office')]
class PointDepList extends Component
{
    public bool $showEditPointDepModal = false;

    public ?int $editingPointDepId = null;

    public string $editPointDepName = '';

    public string $editPointDepMorningSchedule = '';

    public string $editPointDepEveningSchedule = '';

    public string $editPointDepBusStop = '';

    public ?string $editPointDepCity = null;

    public ?float $editPointDepTicketPrice = null;

    public ?string $editPointDepErrorMessage = null;

    public bool $showDeletePointDepModal = false;

    public ?int $deletingPointDepId = null;

    /**
     * Every point de départ, ordered by trajet then by their own position, ready
     * for the table.
     *
     * @return array<int, array{id: int, name: string, trajetName: string|null, busStop: string|null, morningSchedule: string, eveningSchedule: string, city: string|null, ticketPrice: float|null, disabled: bool}>
     */
    #[Computed]
    public function pointDepRows(): array
    {
        return PointDep::query()
            ->with('trajet:id,name')
            ->get()
            ->sortBy([
                fn (PointDep $pointDep) => $pointDep->trajet?->name ?? '',
                fn (PointDep $pointDep) => $pointDep->position,
            ])
            ->map(fn (PointDep $pointDep): array => [
                'id' => $pointDep->id,
                'name' => $pointDep->name,
                'trajetName' => $pointDep->trajet?->name,
                'busStop' => $pointDep->arret_bus,
                'morningSchedule' => $pointDep->heure_point_dep?->format('H:i') ?? '',
                'eveningSchedule' => $pointDep->heure_point_dep_soir?->format('H:i') ?? '',
                'city' => $pointDep->city,
                'ticketPrice' => $pointDep->ticket_price !== null ? (float) $pointDep->ticket_price : null,
                'disabled' => (bool) $pointDep->disabled,
            ])
            ->values()
            ->all();
    }

    public function openEditPointDep(int $pointDepId): void
    {
        $pointDep = PointDep::findOrFail($pointDepId);

        $this->editingPointDepId = $pointDep->id;
        $this->editPointDepName = (string) $pointDep->name;
        $this->editPointDepMorningSchedule = $pointDep->heure_point_dep?->format('H:i') ?? '';
        $this->editPointDepEveningSchedule = $pointDep->heure_point_dep_soir?->format('H:i') ?? '';
        $this->editPointDepBusStop = (string) $pointDep->arret_bus;
        $this->editPointDepCity = $pointDep->city;
        $this->editPointDepTicketPrice = $pointDep->ticket_price !== null ? (float) $pointDep->ticket_price : null;
        $this->editPointDepErrorMessage = null;
        $this->resetValidation();
        $this->showEditPointDepModal = true;
    }

    public function closeEditPointDep(): void
    {
        $this->showEditPointDepModal = false;
        $this->editingPointDepId = null;
        $this->editPointDepErrorMessage = null;
        $this->resetValidation();
    }

    /**
     * Persist the edited point de départ through PointDepController@update.
     */
    public function saveEditedPointDep(): void
    {
        $this->editPointDepErrorMessage = null;

        $this->validate([
            'editPointDepName' => ['required', 'string', 'max:255'],
            'editPointDepMorningSchedule' => ['required', 'date_format:H:i'],
            'editPointDepEveningSchedule' => ['required', 'date_format:H:i'],
            'editPointDepBusStop' => ['required', 'string', 'max:255'],
            'editPointDepCity' => ['nullable', 'string', 'max:255'],
            'editPointDepTicketPrice' => ['nullable', 'numeric', 'min:0'],
        ], attributes: [
            'editPointDepName' => 'nom',
            'editPointDepMorningSchedule' => 'heure du matin',
            'editPointDepEveningSchedule' => 'heure du soir',
            'editPointDepBusStop' => 'arrêt bus',
            'editPointDepCity' => 'ville',
            'editPointDepTicketPrice' => 'prix du ticket',
        ]);

        $pointDep = PointDep::findOrFail($this->editingPointDepId);

        request()->merge([
            'name' => $this->editPointDepName,
            'heurePointDep' => $this->editPointDepMorningSchedule,
            'heurePointDepSoir' => $this->editPointDepEveningSchedule,
            'arretBus' => $this->editPointDepBusStop,
            'city' => $this->editPointDepCity,
            'ticket_price' => $this->editPointDepTicketPrice,
        ]);

        try {
            $legacyResponse = app(PointDepController::class)->update(request(), $pointDep);
        } catch (\Throwable $exception) {
            $this->editPointDepErrorMessage = $exception->getMessage();

            return;
        }

        if ($legacyResponse->getStatusCode() !== SymfonyResponse::HTTP_OK) {
            $this->editPointDepErrorMessage = data_get($legacyResponse->getData(true), 'message', "Le point de départ n'a pas pu être modifié.");

            return;
        }

        unset($this->pointDepRows);
        $this->closeEditPointDep();
        session()->flash('status', 'Le point de départ « '.$this->editPointDepName.' » a été modifié.');
    }

    /**
     * Toggle a point de départ's disabled flag through PointDepController@disable.
     */
    public function togglePointDepDisabled(int $pointDepId): void
    {
        $pointDep = PointDep::findOrFail($pointDepId);

        app(PointDepController::class)->disable($pointDep);

        unset($this->pointDepRows);
        session()->flash('status', $pointDep->fresh()->disabled
            ? 'Le point de départ « '.$pointDep->name.' » a été désactivé.'
            : 'Le point de départ « '.$pointDep->name.' » a été réactivé.');
    }

    public function askToDeletePointDep(int $pointDepId): void
    {
        $this->deletingPointDepId = $pointDepId;
        $this->showDeletePointDepModal = true;
    }

    public function closeDeletePointDepModal(): void
    {
        $this->showDeletePointDepModal = false;
        $this->deletingPointDepId = null;
    }

    public function deletePointDepLabel(): ?string
    {
        if ($this->deletingPointDepId === null) {
            return null;
        }

        return PointDep::findOrFail($this->deletingPointDepId)->name;
    }

    /**
     * Delete the point de départ through PointDepController@destroy.
     */
    public function confirmDeletePointDep(): void
    {
        $pointDepId = $this->deletingPointDepId;

        $this->closeDeletePointDepModal();

        if ($pointDepId === null) {
            return;
        }

        $pointDep = PointDep::findOrFail($pointDepId);
        $pointDepName = $pointDep->name;

        try {
            app(PointDepController::class)->destroy($pointDep);
        } catch (\Throwable $exception) {
            session()->flash('error', $exception->getMessage());

            return;
        }

        unset($this->pointDepRows);
        session()->flash('status', 'Le point de départ « '.$pointDepName.' » a été supprimé.');
    }

    public function render(): View
    {
        return view('livewire.back-office.point-dep-list')->title('Points de départ — Back Office');
    }
}
