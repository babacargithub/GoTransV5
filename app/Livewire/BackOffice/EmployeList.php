<?php

namespace App\Livewire\BackOffice;

use App\Models\Employe;
use App\Models\EmployeCategory;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Back office "Employés" list.
 *
 * Full-page Livewire component: it lists every employé with the details the
 * legacy EmployeController@index exposed (identité, téléphone, poste, permissions,
 * statut) and creates / edits / deletes them in place. EmployeController is only a
 * JSON index + call-log endpoints, so the mutations are plain model writes here.
 */
#[Layout('components.layouts.back-office')]
class EmployeList extends Component
{
    public bool $showEmployeModal = false;

    public ?int $editingEmployeId = null;

    public string $employeFirstName = '';

    public string $employeLastName = '';

    public ?string $employePhoneNumber = null;

    public ?string $employeEmail = null;

    public ?string $employeAddress = null;

    public ?string $employeJobTitle = null;

    public ?string $employeGender = null;

    public ?int $employeCategoryId = null;

    public bool $employeIsActive = true;

    public bool $employeCanSellTicket = false;

    public bool $employeCanCancelPaidBooking = false;

    public bool $employeCanChooseSeats = false;

    public ?string $employeErrorMessage = null;

    public bool $showDeleteEmployeModal = false;

    public ?int $deletingEmployeId = null;

    /**
     * Every employé, ready for the table.
     *
     * @return array<int, array{id: int, fullName: string, phoneNumber: string|null, email: string|null, jobTitle: string|null, categoryName: string|null, isActive: bool, canSellTicket: bool, canCancelPaidBooking: bool, canChooseSeats: bool}>
     */
    #[Computed]
    public function employeRows(): array
    {
        return Employe::query()
            ->with('category:id,name')
            ->orderBy('nom')
            ->orderBy('prenom')
            ->get()
            ->map(fn (Employe $employe): array => [
                'id' => $employe->id,
                'fullName' => $employe->full_name,
                'phoneNumber' => $employe->tel !== null ? (string) $employe->tel : null,
                'email' => $employe->email,
                'jobTitle' => $employe->poste,
                'categoryName' => $employe->category?->name,
                'isActive' => (bool) $employe->actif,
                'canSellTicket' => (bool) $employe->can_sell_ticket,
                'canCancelPaidBooking' => (bool) $employe->can_cancel_paid_booking,
                'canChooseSeats' => (bool) $employe->can_choose_seats,
            ])
            ->all();
    }

    /**
     * Catégorie dropdown options keyed by id.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function employeCategoryOptions(): array
    {
        return EmployeCategory::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function genderOptions(): array
    {
        return ['M' => 'Homme', 'F' => 'Femme'];
    }

    public function openCreateEmploye(): void
    {
        $this->resetEmployeForm();
        $this->editingEmployeId = null;
        $this->showEmployeModal = true;
    }

    public function openEditEmploye(int $employeId): void
    {
        $employe = Employe::findOrFail($employeId);

        $this->editingEmployeId = $employe->id;
        $this->employeFirstName = (string) $employe->prenom;
        $this->employeLastName = (string) $employe->nom;
        $this->employePhoneNumber = $employe->tel !== null ? (string) $employe->tel : null;
        $this->employeEmail = $employe->email;
        $this->employeAddress = $employe->adresse;
        $this->employeJobTitle = $employe->poste;
        $this->employeGender = $employe->sexe;
        $this->employeCategoryId = $employe->employe_category_id;
        $this->employeIsActive = (bool) $employe->actif;
        $this->employeCanSellTicket = (bool) $employe->can_sell_ticket;
        $this->employeCanCancelPaidBooking = (bool) $employe->can_cancel_paid_booking;
        $this->employeCanChooseSeats = (bool) $employe->can_choose_seats;
        $this->employeErrorMessage = null;
        $this->resetValidation();
        $this->showEmployeModal = true;
    }

    public function closeEmployeModal(): void
    {
        $this->showEmployeModal = false;
        $this->editingEmployeId = null;
        $this->employeErrorMessage = null;
        $this->resetValidation();
    }

    private function resetEmployeForm(): void
    {
        $this->employeFirstName = '';
        $this->employeLastName = '';
        $this->employePhoneNumber = null;
        $this->employeEmail = null;
        $this->employeAddress = null;
        $this->employeJobTitle = null;
        $this->employeGender = null;
        $this->employeCategoryId = null;
        $this->employeIsActive = true;
        $this->employeCanSellTicket = false;
        $this->employeCanCancelPaidBooking = false;
        $this->employeCanChooseSeats = false;
        $this->employeErrorMessage = null;
        $this->resetValidation();
    }

    public function saveEmploye(): void
    {
        $this->employeErrorMessage = null;

        $validated = $this->validate([
            'employeFirstName' => ['required', 'string', 'max:150'],
            'employeLastName' => ['required', 'string', 'max:50'],
            'employePhoneNumber' => ['required', 'integer', Rule::unique('employes', 'tel')->ignore($this->editingEmployeId)],
            'employeEmail' => ['required', 'string', 'email', 'max:50'],
            'employeAddress' => ['nullable', 'string', 'max:255'],
            'employeJobTitle' => ['nullable', 'string', 'max:255'],
            'employeGender' => ['nullable', Rule::in(['M', 'F'])],
            'employeCategoryId' => ['nullable', 'integer', Rule::exists('employe_categories', 'id')],
            'employeIsActive' => ['boolean'],
            'employeCanSellTicket' => ['boolean'],
            'employeCanCancelPaidBooking' => ['boolean'],
            'employeCanChooseSeats' => ['boolean'],
        ], attributes: [
            'employeFirstName' => 'prénom',
            'employeLastName' => 'nom',
            'employePhoneNumber' => 'téléphone',
            'employeEmail' => 'email',
            'employeAddress' => 'adresse',
            'employeJobTitle' => 'poste',
            'employeGender' => 'sexe',
            'employeCategoryId' => 'catégorie',
        ]);

        $attributes = [
            'prenom' => $validated['employeFirstName'],
            'nom' => $validated['employeLastName'],
            'tel' => $validated['employePhoneNumber'],
            'email' => $validated['employeEmail'],
            'adresse' => $validated['employeAddress'],
            'poste' => $validated['employeJobTitle'],
            'sexe' => $validated['employeGender'],
            'employe_category_id' => $validated['employeCategoryId'],
            'actif' => $this->employeIsActive,
            'can_sell_ticket' => $this->employeCanSellTicket,
            'can_cancel_paid_booking' => $this->employeCanCancelPaidBooking,
            'can_choose_seats' => $this->employeCanChooseSeats,
        ];

        if ($this->editingEmployeId === null) {
            Employe::create($attributes);
            $statusMessage = 'L\'employé « '.$attributes['prenom'].' '.$attributes['nom'].' » a été créé.';
        } else {
            Employe::findOrFail($this->editingEmployeId)->update($attributes);
            $statusMessage = 'L\'employé « '.$attributes['prenom'].' '.$attributes['nom'].' » a été modifié.';
        }

        unset($this->employeRows);
        $this->closeEmployeModal();
        session()->flash('status', $statusMessage);
    }

    /**
     * Toggle an employé's actif flag.
     */
    public function toggleEmployeActive(int $employeId): void
    {
        $employe = Employe::findOrFail($employeId);
        $employe->update(['actif' => ! $employe->actif]);

        unset($this->employeRows);
        session()->flash('status', $employe->actif
            ? 'L\'employé « '.$employe->full_name.' » a été réactivé.'
            : 'L\'employé « '.$employe->full_name.' » a été désactivé.');
    }

    public function askToDeleteEmploye(int $employeId): void
    {
        $this->deletingEmployeId = $employeId;
        $this->showDeleteEmployeModal = true;
    }

    public function closeDeleteEmployeModal(): void
    {
        $this->showDeleteEmployeModal = false;
        $this->deletingEmployeId = null;
    }

    public function deleteEmployeLabel(): ?string
    {
        if ($this->deletingEmployeId === null) {
            return null;
        }

        return Employe::findOrFail($this->deletingEmployeId)->full_name;
    }

    public function confirmDeleteEmploye(): void
    {
        $employeId = $this->deletingEmployeId;

        $this->closeDeleteEmployeModal();

        if ($employeId === null) {
            return;
        }

        $employe = Employe::findOrFail($employeId);
        $employeName = $employe->full_name;

        try {
            $employe->delete();
        } catch (\Throwable $exception) {
            session()->flash('error', "Impossible de supprimer l'employé : ".$exception->getMessage());

            return;
        }

        unset($this->employeRows);
        session()->flash('status', 'L\'employé « '.$employeName.' » a été supprimé.');
    }

    public function render(): View
    {
        return view('livewire.back-office.employe-list')->title('Employés — Back Office');
    }
}
