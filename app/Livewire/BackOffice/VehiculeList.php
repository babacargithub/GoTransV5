<?php

namespace App\Livewire\BackOffice;

use App\Models\Vehicule;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Back office "Véhicules" list.
 *
 * Full-page Livewire component: it lists the fleet and creates / edits / deletes
 * vehicles in place. VehiculeController is an empty stub, so the mutations are
 * plain model writes here. The seat `template` JSON is left untouched — it is
 * managed elsewhere.
 */
#[Layout('components.layouts.back-office')]
class VehiculeList extends Component
{
    public bool $showVehiculeModal = false;

    public ?int $editingVehiculeId = null;

    public string $vehiculeName = '';

    public ?string $vehiculeRegistrationPlate = null;

    public ?string $vehiculeDriverName = null;

    public ?int $vehiculeNumberOfSeats = null;

    public int $vehiculeType = Vehicule::VEHICULE_TYPE_SIMPLE;

    public ?string $vehiculeDescription = null;

    public bool $vehiculeIsDefault = false;

    public ?string $vehiculeErrorMessage = null;

    public bool $showDeleteVehiculeModal = false;

    public ?int $deletingVehiculeId = null;

    /**
     * Every véhicule, ready for the table.
     *
     * @return array<int, array{id: int, name: string, registrationPlate: string|null, driverName: string|null, numberOfSeats: int|null, typeLabel: string, isDefault: bool}>
     */
    #[Computed]
    public function vehiculeRows(): array
    {
        return Vehicule::query()
            ->orderBy('name')
            ->get()
            ->map(fn (Vehicule $vehicule): array => [
                'id' => $vehicule->id,
                'name' => $vehicule->name,
                'registrationPlate' => $vehicule->matricule,
                'driverName' => $vehicule->chauffeur,
                'numberOfSeats' => $vehicule->nombre_place !== null ? (int) $vehicule->nombre_place : null,
                'typeLabel' => $this->vehiculeTypeOptions[(int) $vehicule->vehicule_type] ?? '—',
                'isDefault' => (bool) $vehicule->default,
            ])
            ->all();
    }

    /**
     * Type de véhicule dropdown options keyed by the stored value.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function vehiculeTypeOptions(): array
    {
        return [
            Vehicule::VEHICULE_TYPE_SIMPLE => 'Simple',
            Vehicule::VEHICULE_TYPE_CLIMATISE => 'Climatisé',
        ];
    }

    public function openCreateVehicule(): void
    {
        $this->resetVehiculeForm();
        $this->editingVehiculeId = null;
        $this->showVehiculeModal = true;
    }

    public function openEditVehicule(int $vehiculeId): void
    {
        $vehicule = Vehicule::findOrFail($vehiculeId);

        $this->editingVehiculeId = $vehicule->id;
        $this->vehiculeName = (string) $vehicule->name;
        $this->vehiculeRegistrationPlate = $vehicule->matricule;
        $this->vehiculeDriverName = $vehicule->chauffeur;
        $this->vehiculeNumberOfSeats = $vehicule->nombre_place !== null ? (int) $vehicule->nombre_place : null;
        $this->vehiculeType = (int) ($vehicule->vehicule_type ?? Vehicule::VEHICULE_TYPE_SIMPLE);
        $this->vehiculeDescription = $vehicule->description;
        $this->vehiculeIsDefault = (bool) $vehicule->default;
        $this->vehiculeErrorMessage = null;
        $this->resetValidation();
        $this->showVehiculeModal = true;
    }

    public function closeVehiculeModal(): void
    {
        $this->showVehiculeModal = false;
        $this->editingVehiculeId = null;
        $this->vehiculeErrorMessage = null;
        $this->resetValidation();
    }

    private function resetVehiculeForm(): void
    {
        $this->vehiculeName = '';
        $this->vehiculeRegistrationPlate = null;
        $this->vehiculeDriverName = null;
        $this->vehiculeNumberOfSeats = null;
        $this->vehiculeType = Vehicule::VEHICULE_TYPE_SIMPLE;
        $this->vehiculeDescription = null;
        $this->vehiculeIsDefault = false;
        $this->vehiculeErrorMessage = null;
        $this->resetValidation();
    }

    public function saveVehicule(): void
    {
        $this->vehiculeErrorMessage = null;

        $validated = $this->validate([
            'vehiculeName' => ['required', 'string', 'max:255'],
            'vehiculeRegistrationPlate' => ['nullable', 'string', 'max:20'],
            'vehiculeDriverName' => ['required', 'string', 'max:255'],
            'vehiculeNumberOfSeats' => ['required', 'integer', 'min:1'],
            'vehiculeType' => ['required', 'integer', Rule::in(array_keys($this->vehiculeTypeOptions))],
            'vehiculeDescription' => ['nullable', 'string', 'max:1000'],
            'vehiculeIsDefault' => ['boolean'],
        ], attributes: [
            'vehiculeName' => 'nom',
            'vehiculeRegistrationPlate' => 'matricule',
            'vehiculeDriverName' => 'chauffeur',
            'vehiculeNumberOfSeats' => 'nombre de places',
            'vehiculeType' => 'type',
            'vehiculeDescription' => 'description',
        ]);

        $attributes = [
            'name' => $validated['vehiculeName'],
            'matricule' => $validated['vehiculeRegistrationPlate'],
            'chauffeur' => $validated['vehiculeDriverName'],
            'nombre_place' => $validated['vehiculeNumberOfSeats'],
            'vehicule_type' => $validated['vehiculeType'],
            'description' => $validated['vehiculeDescription'] ?? '',
            'default' => $this->vehiculeIsDefault,
        ];

        DB::transaction(function () use ($attributes): void {
            if ($this->editingVehiculeId === null) {
                $vehicule = Vehicule::create($attributes);
            } else {
                $vehicule = Vehicule::findOrFail($this->editingVehiculeId);
                $vehicule->update($attributes);
            }

            if ($vehicule->default) {
                Vehicule::query()->whereKeyNot($vehicule->id)->update(['default' => false]);
            }
        });

        unset($this->vehiculeRows);
        $statusMessage = $this->editingVehiculeId === null
            ? 'Le véhicule « '.$attributes['name'].' » a été créé.'
            : 'Le véhicule « '.$attributes['name'].' » a été modifié.';
        $this->closeVehiculeModal();
        session()->flash('status', $statusMessage);
    }

    public function askToDeleteVehicule(int $vehiculeId): void
    {
        $this->deletingVehiculeId = $vehiculeId;
        $this->showDeleteVehiculeModal = true;
    }

    public function closeDeleteVehiculeModal(): void
    {
        $this->showDeleteVehiculeModal = false;
        $this->deletingVehiculeId = null;
    }

    public function deleteVehiculeLabel(): ?string
    {
        if ($this->deletingVehiculeId === null) {
            return null;
        }

        return Vehicule::findOrFail($this->deletingVehiculeId)->name;
    }

    public function confirmDeleteVehicule(): void
    {
        $vehiculeId = $this->deletingVehiculeId;

        $this->closeDeleteVehiculeModal();

        if ($vehiculeId === null) {
            return;
        }

        $vehicule = Vehicule::findOrFail($vehiculeId);
        $vehiculeName = $vehicule->name;

        try {
            $vehicule->delete();
        } catch (\Throwable $exception) {
            session()->flash('error', 'Impossible de supprimer le véhicule : '.$exception->getMessage());

            return;
        }

        unset($this->vehiculeRows);
        session()->flash('status', 'Le véhicule « '.$vehiculeName.' » a été supprimé.');
    }

    public function render(): View
    {
        return view('livewire.back-office.vehicule-list')->title('Véhicules — Back Office');
    }
}
