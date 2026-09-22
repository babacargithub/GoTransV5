<?php

namespace App\Livewire\BackOffice;

use App\Models\Horaire;
use App\Models\Trajet;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Back office "Horaires" list.
 *
 * Full-page Livewire component: it lists every horaire with its trajet and edits
 * / deletes them in place. HoraireController has never been implemented (empty
 * stub), so the mutations are plain model writes here — there is no legacy JSON
 * behaviour to preserve.
 */
#[Layout('components.layouts.back-office')]
class HoraireList extends Component
{
    public bool $showEditHoraireModal = false;

    public ?int $editingHoraireId = null;

    public string $editHoraireName = '';

    public string $editHoraireBusLeaveTime = '';

    public ?string $editHorairePeriode = null;

    public ?int $editHoraireTrajetId = null;

    public bool $showDeleteHoraireModal = false;

    public ?int $deletingHoraireId = null;

    /**
     * Every horaire, with its trajet name.
     *
     * @return array<int, array{id: int, name: string, trajetName: string|null, busLeaveTime: string, periode: string|null}>
     */
    #[Computed]
    public function horaireRows(): array
    {
        return Horaire::query()
            ->with('trajet:id,name')
            ->get()
            ->sortBy([
                fn (Horaire $horaire) => $horaire->trajet?->name ?? '',
                fn (Horaire $horaire) => $horaire->bus_leave_time?->format('H:i') ?? '',
            ])
            ->map(fn (Horaire $horaire): array => [
                'id' => $horaire->id,
                'name' => $horaire->name,
                'trajetName' => $horaire->trajet?->name,
                'busLeaveTime' => $horaire->bus_leave_time?->format('H:i') ?? '',
                'periode' => $horaire->periode,
            ])
            ->values()
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
     * Période dropdown options keyed by the stored value.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function periodeOptions(): array
    {
        return [
            Horaire::PERIODE_MATIN => 'Matin',
            Horaire::PERIODE_APRES_MIDI => 'Après-midi',
            Horaire::PERIODE_NUIT => 'Nuit',
        ];
    }

    public function openEditHoraire(int $horaireId): void
    {
        $horaire = Horaire::findOrFail($horaireId);

        $this->editingHoraireId = $horaire->id;
        $this->editHoraireName = (string) $horaire->name;
        $this->editHoraireBusLeaveTime = $horaire->bus_leave_time?->format('H:i') ?? '';
        $this->editHorairePeriode = $horaire->periode;
        $this->editHoraireTrajetId = $horaire->trajet_id;
        $this->resetValidation();
        $this->showEditHoraireModal = true;
    }

    public function closeEditHoraire(): void
    {
        $this->showEditHoraireModal = false;
        $this->editingHoraireId = null;
        $this->resetValidation();
    }

    public function saveEditedHoraire(): void
    {
        $validated = $this->validate([
            'editHoraireName' => ['required', 'string', 'max:255'],
            'editHoraireBusLeaveTime' => ['required', 'date_format:H:i'],
            'editHorairePeriode' => ['required', Rule::in(Horaire::PERIODES)],
            'editHoraireTrajetId' => ['required', 'integer', Rule::exists('trajets', 'id')],
        ], attributes: [
            'editHoraireName' => 'nom',
            'editHoraireBusLeaveTime' => 'heure de départ du bus',
            'editHorairePeriode' => 'période',
            'editHoraireTrajetId' => 'trajet',
        ]);

        Horaire::findOrFail($this->editingHoraireId)->update([
            'name' => $validated['editHoraireName'],
            'bus_leave_time' => $validated['editHoraireBusLeaveTime'],
            'periode' => $validated['editHorairePeriode'],
            'trajet_id' => $validated['editHoraireTrajetId'],
        ]);

        unset($this->horaireRows);
        $this->closeEditHoraire();
        session()->flash('status', 'L\'horaire « '.$validated['editHoraireName'].' » a été modifié.');
    }

    public function askToDeleteHoraire(int $horaireId): void
    {
        $this->deletingHoraireId = $horaireId;
        $this->showDeleteHoraireModal = true;
    }

    public function closeDeleteHoraireModal(): void
    {
        $this->showDeleteHoraireModal = false;
        $this->deletingHoraireId = null;
    }

    public function deleteHoraireLabel(): ?string
    {
        if ($this->deletingHoraireId === null) {
            return null;
        }

        return Horaire::findOrFail($this->deletingHoraireId)->name;
    }

    public function confirmDeleteHoraire(): void
    {
        $horaireId = $this->deletingHoraireId;

        $this->closeDeleteHoraireModal();

        if ($horaireId === null) {
            return;
        }

        $horaire = Horaire::findOrFail($horaireId);
        $horaireName = $horaire->name;
        $horaire->delete();

        unset($this->horaireRows);
        session()->flash('status', 'L\'horaire « '.$horaireName.' » a été supprimé.');
    }

    public function render(): View
    {
        return view('livewire.back-office.horaire-list')->title('Horaires — Back Office');
    }
}
