<?php

namespace App\Livewire\BackOffice;

use App\Http\Controllers\ItineraryController;
use App\Models\Itinerary;
use App\Models\PointDep;
use App\Models\Trajet;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Back office "Itinéraires" list.
 *
 * Full-page Livewire component: it lists every itinéraire with its trajet and the
 * count of point de départ it covers, and edits / disables / deletes them in
 * place. Editing and deletion reuse untouched ItineraryController methods; the
 * disable toggle is a plain flag write (there is no legacy method for it).
 */
#[Layout('components.layouts.back-office')]
class ItineraireList extends Component
{
    public bool $showEditItineraireModal = false;

    public ?int $editingItineraireId = null;

    public string $editItineraireName = '';

    public ?int $editItineraireTrajetId = null;

    /**
     * Point de départ ids selected for the itinéraire.
     *
     * @var array<int, int>
     */
    public array $editItinerairePointDepIds = [];

    public ?string $editItineraireErrorMessage = null;

    public bool $showDeleteItineraireModal = false;

    public ?int $deletingItineraireId = null;

    /**
     * Every itinéraire, with its trajet name and point de départ count.
     *
     * @return array<int, array{id: int, name: string, trajetName: string|null, pointDepsCount: int, disabled: bool}>
     */
    #[Computed]
    public function itineraireRows(): array
    {
        return Itinerary::query()
            ->with('trajet:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (Itinerary $itinerary): array => [
                'id' => $itinerary->id,
                'name' => $itinerary->name,
                'trajetName' => $itinerary->trajet?->name,
                'pointDepsCount' => count($itinerary->point_deps ?? []),
                'disabled' => (bool) $itinerary->disabled,
            ])
            ->all();
    }

    /**
     * Trajet dropdown options keyed by id.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function trajetOptions(): array
    {
        return Trajet::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * Point de départ of the trajet currently selected in the edit form, for the
     * checkbox list.
     *
     * @return array<int, array{id: int, name: string}>
     */
    #[Computed]
    public function editItinerairePointDepOptions(): array
    {
        if ($this->editItineraireTrajetId === null) {
            return [];
        }

        return PointDep::query()
            ->where('trajet_id', $this->editItineraireTrajetId)
            ->get(['id', 'name'])
            ->map(fn (PointDep $pointDep): array => ['id' => $pointDep->id, 'name' => $pointDep->name])
            ->all();
    }

    public function updatedEditItineraireTrajetId(): void
    {
        $availablePointDepIds = collect($this->editItinerairePointDepOptions)->pluck('id')->all();

        $this->editItinerairePointDepIds = array_values(
            array_intersect($this->editItinerairePointDepIds, $availablePointDepIds)
        );
    }

    public function openEditItineraire(int $itineraireId): void
    {
        $itinerary = Itinerary::findOrFail($itineraireId);

        $this->editingItineraireId = $itinerary->id;
        $this->editItineraireName = (string) $itinerary->name;
        $this->editItineraireTrajetId = $itinerary->trajet_id;
        $this->editItinerairePointDepIds = array_map('intval', $itinerary->point_deps ?? []);
        $this->editItineraireErrorMessage = null;
        $this->resetValidation();
        $this->showEditItineraireModal = true;
    }

    public function closeEditItineraire(): void
    {
        $this->showEditItineraireModal = false;
        $this->editingItineraireId = null;
        $this->editItineraireErrorMessage = null;
        $this->resetValidation();
    }

    /**
     * Persist the edited itinéraire through ItineraryController@update.
     */
    public function saveEditedItineraire(): void
    {
        $this->editItineraireErrorMessage = null;

        $this->validate([
            'editItineraireName' => ['required', 'string', 'max:255', Rule::unique('itineraries', 'name')->ignore($this->editingItineraireId)],
            'editItineraireTrajetId' => ['required', 'integer', Rule::exists('trajets', 'id')],
            'editItinerairePointDepIds' => ['required', 'array', 'min:1'],
            'editItinerairePointDepIds.*' => ['integer', Rule::exists('point_deps', 'id')],
        ], attributes: [
            'editItineraireName' => 'nom',
            'editItineraireTrajetId' => 'trajet',
            'editItinerairePointDepIds' => 'points de départ',
        ]);

        $itinerary = Itinerary::findOrFail($this->editingItineraireId);

        request()->merge([
            'name' => $this->editItineraireName,
            'trajet_id' => $this->editItineraireTrajetId,
            'point_depart_ids' => array_values($this->editItinerairePointDepIds),
        ]);

        try {
            $legacyResponse = app(ItineraryController::class)->update(request(), $itinerary);
        } catch (\Throwable $exception) {
            $this->editItineraireErrorMessage = $exception->getMessage();

            return;
        }

        if ($legacyResponse->getStatusCode() !== SymfonyResponse::HTTP_OK) {
            $this->editItineraireErrorMessage = data_get($legacyResponse->getData(true), 'message', "L'itinéraire n'a pas pu être modifié.");

            return;
        }

        unset($this->itineraireRows);
        $this->closeEditItineraire();
        session()->flash('status', 'L\'itinéraire « '.$this->editItineraireName.' » a été modifié.');
    }

    /**
     * Toggle an itinéraire's disabled flag (no legacy method — plain flag write).
     */
    public function toggleItineraireDisabled(int $itineraireId): void
    {
        $itinerary = Itinerary::findOrFail($itineraireId);
        $itinerary->update(['disabled' => ! $itinerary->disabled]);

        unset($this->itineraireRows);
        session()->flash('status', $itinerary->disabled
            ? 'L\'itinéraire « '.$itinerary->name.' » a été désactivé.'
            : 'L\'itinéraire « '.$itinerary->name.' » a été réactivé.');
    }

    public function askToDeleteItineraire(int $itineraireId): void
    {
        $this->deletingItineraireId = $itineraireId;
        $this->showDeleteItineraireModal = true;
    }

    public function closeDeleteItineraireModal(): void
    {
        $this->showDeleteItineraireModal = false;
        $this->deletingItineraireId = null;
    }

    public function deleteItineraireLabel(): ?string
    {
        if ($this->deletingItineraireId === null) {
            return null;
        }

        return Itinerary::findOrFail($this->deletingItineraireId)->name;
    }

    /**
     * Delete the itinéraire through ItineraryController@destroy.
     */
    public function confirmDeleteItineraire(): void
    {
        $itineraireId = $this->deletingItineraireId;

        $this->closeDeleteItineraireModal();

        if ($itineraireId === null) {
            return;
        }

        $itinerary = Itinerary::findOrFail($itineraireId);
        $itineraireName = $itinerary->name;

        try {
            app(ItineraryController::class)->destroy($itinerary);
        } catch (\Throwable $exception) {
            session()->flash('error', $exception->getMessage());

            return;
        }

        unset($this->itineraireRows);
        session()->flash('status', 'L\'itinéraire « '.$itineraireName.' » a été supprimé.');
    }

    public function render(): View
    {
        return view('livewire.back-office.itineraire-list')->title('Itinéraires — Back Office');
    }
}
