<?php

namespace App\Livewire\BackOffice;

use App\Enums\PermissionName;
use App\Models\ExpenseType;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Back office "Types de dépense" page (Finances menu): create, rename, activate / deactivate and
 * delete the categories every expense is classified under (see ExpenseType).
 *
 * System types (looked up by code to classify bus rentals, ticket refunds...) can be renamed and
 * deactivated — a deactivated type only disappears from the sortie de caisse dropdown — but never
 * deleted. A type already used by transactions cannot be deleted either, since reports rely on it.
 *
 * Restricted to full-access users, like the rest of the Finances menu.
 */
#[Layout('components.layouts.back-office')]
class ExpenseTypeList extends Component
{
    public bool $showExpenseTypeModal = false;

    public ?int $editingExpenseTypeId = null;

    public string $expenseTypeName = '';

    public bool $showDeleteExpenseTypeModal = false;

    public ?int $deletingExpenseTypeId = null;

    public function mount(): void
    {
        PermissionName::FullAccess->authorizeForCurrentUser();
    }

    /**
     * @return array<int, array{id: int, name: string, isSystem: bool, isActive: bool, isPlannedMonthly: bool, transactionsCount: int, canBeDeleted: bool}>
     */
    #[Computed]
    public function expenseTypeRows(): array
    {
        return ExpenseType::query()
            ->withCount('accountTransactions')
            ->orderBy('name')
            ->get()
            ->map(fn (ExpenseType $expenseType): array => [
                'id' => $expenseType->id,
                'name' => $expenseType->name,
                'isSystem' => $expenseType->is_system,
                'isActive' => $expenseType->is_active,
                'isPlannedMonthly' => $expenseType->is_planned_monthly,
                'transactionsCount' => $expenseType->account_transactions_count,
                'canBeDeleted' => ! $expenseType->is_system && $expenseType->account_transactions_count === 0,
            ])
            ->all();
    }

    public function openCreateExpenseType(): void
    {
        $this->editingExpenseTypeId = null;
        $this->expenseTypeName = '';
        $this->resetValidation();
        $this->showExpenseTypeModal = true;
    }

    public function openEditExpenseType(int $expenseTypeId): void
    {
        $expenseType = ExpenseType::findOrFail($expenseTypeId);

        $this->editingExpenseTypeId = $expenseType->id;
        $this->expenseTypeName = $expenseType->name;
        $this->resetValidation();
        $this->showExpenseTypeModal = true;
    }

    public function closeExpenseTypeModal(): void
    {
        $this->showExpenseTypeModal = false;
        $this->editingExpenseTypeId = null;
        $this->resetValidation();
    }

    public function saveExpenseType(): void
    {
        PermissionName::FullAccess->authorizeForCurrentUser();

        $validated = $this->validate([
            'expenseTypeName' => [
                'required',
                'string',
                'max:255',
                Rule::unique('expense_types', 'name')->ignore($this->editingExpenseTypeId),
            ],
        ], attributes: ['expenseTypeName' => 'nom']);

        $expenseTypeName = trim($validated['expenseTypeName']);

        if ($this->editingExpenseTypeId === null) {
            ExpenseType::create(['name' => $expenseTypeName]);
            $statusMessage = 'Le type de dépense « '.$expenseTypeName.' » a été créé.';
        } else {
            ExpenseType::findOrFail($this->editingExpenseTypeId)->update(['name' => $expenseTypeName]);
            $statusMessage = 'Le type de dépense « '.$expenseTypeName.' » a été modifié.';
        }

        unset($this->expenseTypeRows);
        $this->closeExpenseTypeModal();
        session()->flash('status', $statusMessage);
    }

    public function toggleExpenseTypeActive(int $expenseTypeId): void
    {
        PermissionName::FullAccess->authorizeForCurrentUser();

        $expenseType = ExpenseType::findOrFail($expenseTypeId);
        $expenseType->update(['is_active' => ! $expenseType->is_active]);

        unset($this->expenseTypeRows);
    }

    public function toggleExpenseTypePlannedMonthly(int $expenseTypeId): void
    {
        PermissionName::FullAccess->authorizeForCurrentUser();

        $expenseType = ExpenseType::findOrFail($expenseTypeId);
        $expenseType->update(['is_planned_monthly' => ! $expenseType->is_planned_monthly]);

        unset($this->expenseTypeRows);
    }

    public function askToDeleteExpenseType(int $expenseTypeId): void
    {
        $this->deletingExpenseTypeId = $expenseTypeId;
        $this->showDeleteExpenseTypeModal = true;
    }

    public function closeDeleteExpenseTypeModal(): void
    {
        $this->showDeleteExpenseTypeModal = false;
        $this->deletingExpenseTypeId = null;
    }

    public function deleteExpenseTypeLabel(): ?string
    {
        return $this->deletingExpenseTypeId === null
            ? null
            : ExpenseType::find($this->deletingExpenseTypeId)?->name;
    }

    public function confirmDeleteExpenseType(): void
    {
        PermissionName::FullAccess->authorizeForCurrentUser();

        $expenseTypeId = $this->deletingExpenseTypeId;
        $this->closeDeleteExpenseTypeModal();

        if ($expenseTypeId === null) {
            return;
        }

        $expenseType = ExpenseType::withCount('accountTransactions')->findOrFail($expenseTypeId);

        if ($expenseType->is_system) {
            session()->flash('error', 'Impossible de supprimer « '.$expenseType->name.' » : c\'est un type système.');

            return;
        }

        if ($expenseType->account_transactions_count > 0) {
            session()->flash('error', 'Impossible de supprimer « '.$expenseType->name.' » : il est utilisé par '.$expenseType->account_transactions_count.' opération(s). Désactivez-le plutôt.');

            return;
        }

        $expenseType->delete();

        unset($this->expenseTypeRows);
        session()->flash('status', 'Le type de dépense « '.$expenseType->name.' » a été supprimé.');
    }

    public function render(): View
    {
        return view('livewire.back-office.expense-type-list')->title('Types de dépense — Back Office');
    }
}
