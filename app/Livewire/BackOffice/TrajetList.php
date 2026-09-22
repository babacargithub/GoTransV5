<?php

namespace App\Livewire\BackOffice;

use App\Http\Controllers\PointDepController;
use App\Http\Controllers\TrajetController;
use App\Models\Destination;
use App\Models\PointDep;
use App\Models\Trajet;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Back office "Trajets" list.
 *
 * Full-page interactive Livewire component: it lists every trajet with a badge
 * for its point de départ count and one for its destination count. Clicking a
 * badge opens a dialog to add / edit / delete the point de départ (reusing
 * untouched PointDepController methods) or the destinations (plain model writes —
 * DestinationController is an empty stub) of that trajet. Trajet edit / delete
 * reuse TrajetController (TrajetService) unchanged.
 */
#[Layout('components.layouts.back-office')]
class TrajetList extends Component
{
    /**
     * Per-trajet "actif" switch state, keyed by trajet id. `true` means the trajet
     * is active (its `disabled` column is `false`). Bound with `wire:model.live`
     * so flipping a row's switch fires {@see self::updatedTrajetActiveStates()}.
     *
     * @var array<int, bool>
     */
    public array $trajetActiveStates = [];

    /* ---- trajet edit / delete ---- */

    public bool $showEditTrajetModal = false;

    public ?int $editingTrajetId = null;

    public string $editTrajetName = '';

    public ?string $editTrajetPublicName = null;

    public ?string $editTrajetDepartureCity = null;

    public ?string $editTrajetArrivalCity = null;

    public ?string $editTrajetCode = null;

    public ?float $editTrajetLength = null;

    public int $editTrajetDisplayPosition = 0;

    public ?string $editTrajetErrorMessage = null;

    public bool $showDeleteTrajetModal = false;

    public ?int $deletingTrajetId = null;

    /* ---- point de départ dialog ---- */

    public bool $showPointDepsDialog = false;

    public ?int $pointDepsDialogTrajetId = null;

    public bool $showPointDepForm = false;

    /**
     * Editing state for the point de départ form. `id` null means "new".
     *
     * @var array{id: int|null, name: string, morningSchedule: string, eveningSchedule: string, busStop: string, city: string|null, ticketPrice: float|null}
     */
    public array $pointDepForm = [
        'id' => null,
        'name' => '',
        'morningSchedule' => '',
        'eveningSchedule' => '',
        'busStop' => '',
        'city' => null,
        'ticketPrice' => null,
    ];

    public ?string $pointDepFormErrorMessage = null;

    /* ---- destination dialog ---- */

    public bool $showDestinationsDialog = false;

    public ?int $destinationsDialogTrajetId = null;

    public bool $showDestinationForm = false;

    /**
     * Editing state for the destination form. `id` null means "new".
     *
     * @var array{id: int|null, name: string, tarif: float|null}
     */
    public array $destinationForm = [
        'id' => null,
        'name' => '',
        'tarif' => null,
    ];

    public ?string $destinationFormErrorMessage = null;

    public function mount(): void
    {
        $this->syncTrajetActiveStates();
    }

    /**
     * Rebuild the per-row "actif" switch state from the trajets' `disabled` column.
     */
    private function syncTrajetActiveStates(): void
    {
        $this->trajetActiveStates = Trajet::query()
            ->orderBy('display_position')
            ->orderBy('name')
            ->pluck('disabled', 'id')
            ->map(fn (bool $disabled): bool => ! $disabled)
            ->all();
    }

    /**
     * Every trajet with its point de départ and destination counts.
     *
     * @return array<int, array{id: int, name: string, publicName: string|null, departureCity: string|null, arrivalCity: string|null, displayPosition: int, disabled: bool, pointDepsCount: int, destinationsCount: int}>
     */
    #[Computed]
    public function trajetRows(): array
    {
        return Trajet::query()
            ->withCount(['pointDeps', 'destinations'])
            ->orderBy('display_position')
            ->orderBy('name')
            ->get()
            ->map(fn (Trajet $trajet): array => [
                'id' => $trajet->id,
                'name' => $trajet->name,
                'publicName' => $trajet->public_name,
                'departureCity' => $trajet->departure_city,
                'arrivalCity' => $trajet->arrival_city,
                'displayPosition' => $trajet->display_position,
                'disabled' => $trajet->disabled,
                'pointDepsCount' => $trajet->point_deps_count,
                'destinationsCount' => $trajet->destinations_count,
            ])
            ->all();
    }

    /**
     * Persist a row's "actif" switch: `disabled` is the inverse of the switch value.
     * Public website listings hide disabled trajets.
     */
    public function updatedTrajetActiveStates(bool $isActive, string $trajetId): void
    {
        $trajet = Trajet::findOrFail((int) $trajetId);
        $trajet->update(['disabled' => ! $isActive]);

        unset($this->trajetRows);

        session()->flash('status', $trajet->disabled
            ? 'Le trajet « '.$trajet->name.' » a été désactivé et n\'apparaît plus sur le site public.'
            : 'Le trajet « '.$trajet->name.' » a été réactivé.');
    }

    /* ================= trajet edit / delete ================= */

    public function openEditTrajet(int $trajetId): void
    {
        $trajet = Trajet::findOrFail($trajetId);

        $this->editingTrajetId = $trajet->id;
        $this->editTrajetName = (string) $trajet->name;
        $this->editTrajetPublicName = $trajet->public_name;
        $this->editTrajetDepartureCity = $trajet->departure_city;
        $this->editTrajetArrivalCity = $trajet->arrival_city;
        $this->editTrajetCode = $trajet->code;
        $this->editTrajetLength = $trajet->length !== null ? (float) $trajet->length : null;
        $this->editTrajetDisplayPosition = (int) $trajet->display_position;
        $this->editTrajetErrorMessage = null;
        $this->resetValidation();
        $this->showEditTrajetModal = true;
    }

    public function closeEditTrajet(): void
    {
        $this->showEditTrajetModal = false;
        $this->editingTrajetId = null;
        $this->editTrajetErrorMessage = null;
        $this->resetValidation();
    }

    /**
     * Persist the edited trajet through TrajetController@update (TrajetService).
     */
    public function saveEditedTrajet(): void
    {
        $this->editTrajetErrorMessage = null;

        $this->validate([
            'editTrajetName' => ['required', 'string', 'max:255', Rule::unique('trajets', 'name')->ignore($this->editingTrajetId)],
            'editTrajetPublicName' => ['nullable', 'string', 'max:255'],
            'editTrajetDepartureCity' => ['nullable', 'string', 'max:255'],
            'editTrajetArrivalCity' => ['nullable', 'string', 'max:255'],
            'editTrajetCode' => ['nullable', 'string', 'max:255', Rule::unique('trajets', 'code')->ignore($this->editingTrajetId)],
            'editTrajetLength' => ['nullable', 'numeric', 'min:0'],
            'editTrajetDisplayPosition' => ['required', 'integer', 'min:0'],
        ], attributes: [
            'editTrajetName' => 'nom',
            'editTrajetPublicName' => 'nom public',
            'editTrajetDepartureCity' => 'ville de départ',
            'editTrajetArrivalCity' => "ville d'arrivée",
            'editTrajetCode' => 'code',
            'editTrajetLength' => 'distance',
            'editTrajetDisplayPosition' => "position d'affichage",
        ]);

        $trajet = Trajet::findOrFail($this->editingTrajetId);

        request()->merge([
            'name' => $this->editTrajetName,
            'public_name' => $this->editTrajetPublicName,
            'departure_city' => $this->editTrajetDepartureCity,
            'arrival_city' => $this->editTrajetArrivalCity,
            'code' => $this->editTrajetCode,
            'length' => $this->editTrajetLength,
        ]);

        try {
            app(TrajetController::class)->update(request(), $trajet);
        } catch (\Throwable $exception) {
            $this->editTrajetErrorMessage = $exception->getMessage();

            return;
        }

        // `display_position` is a new column with no legacy controller path — persist it
        // directly, like the `disabled` row toggle does.
        $trajet->update(['display_position' => $this->editTrajetDisplayPosition]);

        unset($this->trajetRows);
        $this->closeEditTrajet();
        session()->flash('status', 'Le trajet « '.$this->editTrajetName.' » a été modifié.');
    }

    public function askToDeleteTrajet(int $trajetId): void
    {
        $this->deletingTrajetId = $trajetId;
        $this->showDeleteTrajetModal = true;
    }

    public function closeDeleteTrajetModal(): void
    {
        $this->showDeleteTrajetModal = false;
        $this->deletingTrajetId = null;
    }

    public function deleteTrajetLabel(): ?string
    {
        if ($this->deletingTrajetId === null) {
            return null;
        }

        return Trajet::findOrFail($this->deletingTrajetId)->name;
    }

    /**
     * Delete the trajet through TrajetController@destroy (TrajetService).
     */
    public function confirmDeleteTrajet(): void
    {
        $trajetId = $this->deletingTrajetId;

        $this->closeDeleteTrajetModal();

        if ($trajetId === null) {
            return;
        }

        $trajet = Trajet::findOrFail($trajetId);
        $trajetName = $trajet->name;

        try {
            app(TrajetController::class)->destroy($trajet);
        } catch (\Throwable $exception) {
            session()->flash('error', $exception->getMessage());

            return;
        }

        unset($this->trajetRows);
        $this->syncTrajetActiveStates();
        session()->flash('status', 'Le trajet « '.$trajetName.' » a été supprimé.');
    }

    /* ================= point de départ dialog ================= */

    public function openPointDepsDialog(int $trajetId): void
    {
        $this->pointDepsDialogTrajetId = $trajetId;
        $this->showPointDepForm = false;
        $this->pointDepFormErrorMessage = null;
        $this->resetValidation();
        $this->showPointDepsDialog = true;
    }

    public function closePointDepsDialog(): void
    {
        $this->showPointDepsDialog = false;
        $this->pointDepsDialogTrajetId = null;
        $this->showPointDepForm = false;
        $this->pointDepFormErrorMessage = null;
        $this->resetValidation();
    }

    public function dialogTrajetLabel(): ?string
    {
        $trajetId = $this->pointDepsDialogTrajetId ?? $this->destinationsDialogTrajetId;

        if ($trajetId === null) {
            return null;
        }

        return Trajet::findOrFail($trajetId)->name;
    }

    /**
     * Point de départ of the trajet the dialog is open on.
     *
     * @return array<int, array{id: int, name: string, busStop: string|null, morningSchedule: string, eveningSchedule: string, disabled: bool}>
     */
    #[Computed]
    public function dialogPointDepRows(): array
    {
        if ($this->pointDepsDialogTrajetId === null) {
            return [];
        }

        return PointDep::query()
            ->where('trajet_id', $this->pointDepsDialogTrajetId)
            ->get()
            ->map(fn (PointDep $pointDep): array => [
                'id' => $pointDep->id,
                'name' => $pointDep->name,
                'busStop' => $pointDep->arret_bus,
                'morningSchedule' => $pointDep->heure_point_dep?->format('H:i') ?? '',
                'eveningSchedule' => $pointDep->heure_point_dep_soir?->format('H:i') ?? '',
                'disabled' => (bool) $pointDep->disabled,
            ])
            ->all();
    }

    public function startAddingPointDep(): void
    {
        $this->pointDepForm = [
            'id' => null,
            'name' => '',
            'morningSchedule' => '',
            'eveningSchedule' => '',
            'busStop' => '',
            'city' => null,
            'ticketPrice' => null,
        ];
        $this->pointDepFormErrorMessage = null;
        $this->resetValidation();
        $this->showPointDepForm = true;
    }

    public function startEditingPointDep(int $pointDepId): void
    {
        $pointDep = PointDep::findOrFail($pointDepId);

        $this->pointDepForm = [
            'id' => $pointDep->id,
            'name' => (string) $pointDep->name,
            'morningSchedule' => $pointDep->heure_point_dep?->format('H:i') ?? '',
            'eveningSchedule' => $pointDep->heure_point_dep_soir?->format('H:i') ?? '',
            'busStop' => (string) $pointDep->arret_bus,
            'city' => $pointDep->city,
            'ticketPrice' => $pointDep->ticket_price !== null ? (float) $pointDep->ticket_price : null,
        ];
        $this->pointDepFormErrorMessage = null;
        $this->resetValidation();
        $this->showPointDepForm = true;
    }

    public function cancelPointDepForm(): void
    {
        $this->showPointDepForm = false;
        $this->pointDepFormErrorMessage = null;
        $this->resetValidation();
    }

    /**
     * Create or update the point de départ through PointDepController@store /
     *
     * @update.
     */
    public function savePointDepForm(): void
    {
        $this->pointDepFormErrorMessage = null;

        $this->validate([
            'pointDepForm.name' => ['required', 'string', 'max:255'],
            'pointDepForm.morningSchedule' => ['required', 'date_format:H:i'],
            'pointDepForm.eveningSchedule' => ['required', 'date_format:H:i'],
            'pointDepForm.busStop' => ['required', 'string', 'max:255'],
            'pointDepForm.city' => ['nullable', 'string', 'max:255'],
            'pointDepForm.ticketPrice' => ['nullable', 'numeric', 'min:0'],
        ], attributes: [
            'pointDepForm.name' => 'nom',
            'pointDepForm.morningSchedule' => 'heure du matin',
            'pointDepForm.eveningSchedule' => 'heure du soir',
            'pointDepForm.busStop' => 'arrêt bus',
            'pointDepForm.city' => 'ville',
            'pointDepForm.ticketPrice' => 'prix du ticket',
        ]);

        $payload = [
            'name' => $this->pointDepForm['name'],
            'heurePointDep' => $this->pointDepForm['morningSchedule'],
            'heurePointDepSoir' => $this->pointDepForm['eveningSchedule'],
            'arretBus' => $this->pointDepForm['busStop'],
            'city' => $this->pointDepForm['city'],
            'ticket_price' => $this->pointDepForm['ticketPrice'],
        ];

        try {
            if ($this->pointDepForm['id'] === null) {
                request()->merge($payload + ['trajet_id' => $this->pointDepsDialogTrajetId]);
                $legacyResponse = app(PointDepController::class)->store(request());
                $expectedStatus = SymfonyResponse::HTTP_CREATED;
            } else {
                request()->merge($payload);
                $legacyResponse = app(PointDepController::class)->update(request(), PointDep::findOrFail($this->pointDepForm['id']));
                $expectedStatus = SymfonyResponse::HTTP_OK;
            }
        } catch (\Throwable $exception) {
            $this->pointDepFormErrorMessage = $exception->getMessage();

            return;
        }

        if ($legacyResponse->getStatusCode() !== $expectedStatus) {
            $this->pointDepFormErrorMessage = data_get($legacyResponse->getData(true), 'message', "Le point de départ n'a pas pu être enregistré.");

            return;
        }

        unset($this->dialogPointDepRows, $this->trajetRows);
        $this->showPointDepForm = false;
    }

    /**
     * Delete a point de départ through PointDepController@destroy.
     */
    public function deletePointDep(int $pointDepId): void
    {
        $pointDep = PointDep::findOrFail($pointDepId);

        try {
            app(PointDepController::class)->destroy($pointDep);
        } catch (\Throwable $exception) {
            $this->pointDepFormErrorMessage = $exception->getMessage();

            return;
        }

        unset($this->dialogPointDepRows, $this->trajetRows);
    }

    /* ================= destination dialog ================= */

    public function openDestinationsDialog(int $trajetId): void
    {
        $this->destinationsDialogTrajetId = $trajetId;
        $this->showDestinationForm = false;
        $this->destinationFormErrorMessage = null;
        $this->resetValidation();
        $this->showDestinationsDialog = true;
    }

    public function closeDestinationsDialog(): void
    {
        $this->showDestinationsDialog = false;
        $this->destinationsDialogTrajetId = null;
        $this->showDestinationForm = false;
        $this->destinationFormErrorMessage = null;
        $this->resetValidation();
    }

    /**
     * Destinations of the trajet the dialog is open on.
     *
     * @return array<int, array{id: int, name: string, tarif: float|null}>
     */
    #[Computed]
    public function dialogDestinationRows(): array
    {
        if ($this->destinationsDialogTrajetId === null) {
            return [];
        }

        return Destination::query()
            ->where('trajet_id', $this->destinationsDialogTrajetId)
            ->orderBy('name')
            ->get()
            ->map(fn (Destination $destination): array => [
                'id' => $destination->id,
                'name' => $destination->name,
                'tarif' => $destination->tarif !== null ? (float) $destination->tarif : null,
            ])
            ->all();
    }

    public function startAddingDestination(): void
    {
        $this->destinationForm = ['id' => null, 'name' => '', 'tarif' => null];
        $this->destinationFormErrorMessage = null;
        $this->resetValidation();
        $this->showDestinationForm = true;
    }

    public function startEditingDestination(int $destinationId): void
    {
        $destination = Destination::findOrFail($destinationId);

        $this->destinationForm = [
            'id' => $destination->id,
            'name' => (string) $destination->name,
            'tarif' => $destination->tarif !== null ? (float) $destination->tarif : null,
        ];
        $this->destinationFormErrorMessage = null;
        $this->resetValidation();
        $this->showDestinationForm = true;
    }

    public function cancelDestinationForm(): void
    {
        $this->showDestinationForm = false;
        $this->destinationFormErrorMessage = null;
        $this->resetValidation();
    }

    /**
     * Create or update the destination (plain model writes — DestinationController
     * is an empty stub).
     */
    public function saveDestinationForm(): void
    {
        $validated = $this->validate([
            'destinationForm.name' => ['required', 'string', 'max:255'],
            'destinationForm.tarif' => ['nullable', 'numeric', 'min:0'],
        ], attributes: [
            'destinationForm.name' => 'nom',
            'destinationForm.tarif' => 'tarif',
        ]);

        $name = $validated['destinationForm']['name'];
        $tarif = $validated['destinationForm']['tarif'];

        if ($this->destinationForm['id'] === null) {
            $trajet = Trajet::findOrFail($this->destinationsDialogTrajetId);
            $trajet->destinations()->create($tarif !== null
                ? ['name' => $name, 'tarif' => $tarif]
                : ['name' => $name]);
        } else {
            $destination = Destination::findOrFail($this->destinationForm['id']);
            $destination->name = $name;
            if ($tarif !== null) {
                $destination->tarif = $tarif;
            }
            $destination->save();
        }

        unset($this->dialogDestinationRows, $this->trajetRows);
        $this->showDestinationForm = false;
    }

    public function deleteDestination(int $destinationId): void
    {
        Destination::findOrFail($destinationId)->delete();

        unset($this->dialogDestinationRows, $this->trajetRows);
    }

    public function render(): View
    {
        return view('livewire.back-office.trajet-list')->title('Trajets — Back Office');
    }
}
