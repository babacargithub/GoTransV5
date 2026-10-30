<?php

namespace App\Livewire\BackOffice;

use App\Enums\AmbassadorStatus;
use App\Enums\PermissionName;
use App\Models\Ambassador;
use App\Services\AmbassadorService;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Back office "Ambassadeurs" list.
 *
 * Lists ambassadors (search + status filter) and manages them in place: create / edit, approve or
 * reject a pending (sponsored) ambassador, suspend / reactivate, delete. All business rules live
 * in {@see AmbassadorService}; this component only checks permissions and surfaces the outcome.
 */
#[Layout('components.layouts.back-office')]
class AmbassadorList extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $searchTerm = '';

    #[Url(as: 'statut')]
    public string $statusFilter = '';

    public bool $showAmbassadorModal = false;

    public ?int $editingAmbassadorId = null;

    public string $ambassadorFirstName = '';

    public string $ambassadorLastName = '';

    public string $ambassadorPhoneNumber = '';

    public ?string $ambassadorWavePhoneNumber = null;

    public string $ambassadorCode = '';

    public ?string $contractStartsAt = null;

    public ?string $contractEndsAt = null;

    public bool $createUserAccount = false;

    public string $accountUsername = '';

    public string $accountPassword = '';

    public bool $showApprovalModal = false;

    public ?int $approvingAmbassadorId = null;

    public bool $showDeleteModal = false;

    public ?int $deletingAmbassadorId = null;

    public function updatedSearchTerm(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function ambassadors(): LengthAwarePaginator
    {
        return app(AmbassadorService::class)->paginateForBackOffice(
            searchTerm: $this->searchTerm,
            status: AmbassadorStatus::tryFrom($this->statusFilter),
        );
    }

    /**
     * @return array<string, string> status value => French label
     */
    #[Computed]
    public function statusOptions(): array
    {
        return collect(AmbassadorStatus::cases())
            ->mapWithKeys(fn (AmbassadorStatus $status): array => [$status->value => $status->label()])
            ->all();
    }

    /**
     * What the current user may do, resolved once per render instead of once per row.
     *
     * @return array{create: bool, createAccount: bool, edit: bool, approve: bool, suspend: bool, delete: bool}
     */
    #[Computed]
    public function abilities(): array
    {
        return [
            'create' => PermissionName::CreateAmbassador->allowedForCurrentUser(),
            'createAccount' => PermissionName::CreateUser->allowedForCurrentUser(),
            'edit' => PermissionName::EditAmbassador->allowedForCurrentUser(),
            'approve' => PermissionName::ApproveAmbassador->allowedForCurrentUser(),
            'suspend' => PermissionName::SuspendAmbassador->allowedForCurrentUser(),
            'delete' => PermissionName::DeleteAmbassador->allowedForCurrentUser(),
        ];
    }

    public function openCreateAmbassador(): void
    {
        if (! $this->allowed(PermissionName::CreateAmbassador)) {
            return;
        }

        $this->resetAmbassadorForm();
        $this->contractStartsAt = today()->toDateString();
        $this->contractEndsAt = today()->addYear()->toDateString();
        $this->showAmbassadorModal = true;
    }

    public function openEditAmbassador(int $ambassadorId): void
    {
        if (! $this->allowed(PermissionName::EditAmbassador)) {
            return;
        }

        $ambassador = Ambassador::findOrFail($ambassadorId);

        $this->resetAmbassadorForm();
        $this->editingAmbassadorId = $ambassador->id;
        $this->ambassadorFirstName = $ambassador->first_name;
        $this->ambassadorLastName = $ambassador->last_name;
        $this->ambassadorPhoneNumber = $ambassador->phone_number;
        $this->ambassadorWavePhoneNumber = $ambassador->wave_phone_number;
        $this->ambassadorCode = $ambassador->code;
        $this->contractStartsAt = $ambassador->contract_starts_at?->toDateString();
        $this->contractEndsAt = $ambassador->contract_ends_at?->toDateString();
        $this->showAmbassadorModal = true;
    }

    public function closeAmbassadorModal(): void
    {
        $this->showAmbassadorModal = false;
        $this->resetAmbassadorForm();
    }

    public function saveAmbassador(AmbassadorService $ambassadorService): void
    {
        $requiredPermission = $this->editingAmbassadorId === null
            ? PermissionName::CreateAmbassador
            : PermissionName::EditAmbassador;

        if (! $this->allowed($requiredPermission)) {
            return;
        }

        $shouldCreateAccount = $this->editingAmbassadorId === null
            && $this->createUserAccount
            && $this->allowed(PermissionName::CreateUser);

        if ($this->createUserAccount && $this->editingAmbassadorId === null && ! $shouldCreateAccount) {
            return;
        }

        $accountRules = $shouldCreateAccount ? [
            'accountUsername' => ['required', 'string', 'max:190', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('users', 'username')],
            'accountPassword' => ['required', 'string', 'min:8', 'max:255'],
        ] : [];

        $validated = $this->validate([
            ...$accountRules,
            'ambassadorFirstName' => ['required', 'string', 'max:100'],
            'ambassadorLastName' => ['required', 'string', 'max:100'],
            'ambassadorPhoneNumber' => ['required', 'digits:9', Rule::unique('ambassadors', 'phone_number')->ignore($this->editingAmbassadorId)],
            'ambassadorWavePhoneNumber' => ['nullable', 'digits:9'],
            'ambassadorCode' => ['nullable', 'string', 'alpha_num', 'min:3', 'max:20', Rule::unique('ambassadors', 'code')->ignore($this->editingAmbassadorId)],
            'contractStartsAt' => ['nullable', 'date'],
            'contractEndsAt' => ['required', 'date', 'after_or_equal:contractStartsAt'],
        ], attributes: $this->validationAttributes());

        $attributes = [
            'first_name' => $validated['ambassadorFirstName'],
            'last_name' => $validated['ambassadorLastName'],
            'phone_number' => $validated['ambassadorPhoneNumber'],
            'wave_phone_number' => $validated['ambassadorWavePhoneNumber'],
            'code' => $validated['ambassadorCode'],
            'contract_starts_at' => $validated['contractStartsAt'],
            'contract_ends_at' => $validated['contractEndsAt'],
        ];

        if ($this->editingAmbassadorId === null) {
            $ambassador = $ambassadorService->createActiveAmbassador(
                $attributes,
                auth()->user(),
                $shouldCreateAccount ? [
                    'username' => $validated['accountUsername'],
                    'password' => $validated['accountPassword'],
                ] : null,
            );
            $statusMessage = "L'ambassadeur « {$ambassador->full_name} » a été créé (code {$ambassador->code})"
                .($shouldCreateAccount ? ' avec son compte utilisateur.' : '.');
        } else {
            // A blank code on edit means "keep the current one".
            if (blank($attributes['code'])) {
                unset($attributes['code']);
            }

            $ambassador = $ambassadorService->updateAmbassador(Ambassador::findOrFail($this->editingAmbassadorId), $attributes);
            $statusMessage = "L'ambassadeur « {$ambassador->full_name} » a été modifié.";
        }

        $this->closeAmbassadorModal();
        session()->flash('status', $statusMessage);
    }

    public function openApproval(int $ambassadorId): void
    {
        if (! $this->allowed(PermissionName::ApproveAmbassador)) {
            return;
        }

        $this->approvingAmbassadorId = $ambassadorId;
        $this->contractStartsAt = today()->toDateString();
        $this->contractEndsAt = today()->addYear()->toDateString();
        $this->resetValidation();
        $this->showApprovalModal = true;
    }

    public function closeApprovalModal(): void
    {
        $this->showApprovalModal = false;
        $this->approvingAmbassadorId = null;
        $this->resetAmbassadorForm();
    }

    public function approveAmbassador(AmbassadorService $ambassadorService): void
    {
        if (! $this->allowed(PermissionName::ApproveAmbassador)) {
            return;
        }

        $validated = $this->validate([
            'contractStartsAt' => ['required', 'date'],
            'contractEndsAt' => ['required', 'date', 'after_or_equal:contractStartsAt'],
        ], attributes: $this->validationAttributes());

        $this->runLifecycleAction(function () use ($ambassadorService, $validated): string {
            $ambassador = $ambassadorService->approveAmbassador(
                Ambassador::findOrFail($this->approvingAmbassadorId),
                auth()->user(),
                $validated['contractStartsAt'],
                $validated['contractEndsAt'],
            );

            return "L'ambassadeur « {$ambassador->full_name} » a été validé.";
        });

        $this->closeApprovalModal();
    }

    public function rejectAmbassador(int $ambassadorId, AmbassadorService $ambassadorService): void
    {
        if (! $this->allowed(PermissionName::ApproveAmbassador)) {
            return;
        }

        $this->runLifecycleAction(fn (): string => "L'ambassadeur « {$ambassadorService->rejectAmbassador(Ambassador::findOrFail($ambassadorId), auth()->user())->full_name} » a été refusé.");
    }

    public function suspendAmbassador(int $ambassadorId, AmbassadorService $ambassadorService): void
    {
        if (! $this->allowed(PermissionName::SuspendAmbassador)) {
            return;
        }

        $this->runLifecycleAction(fn (): string => "L'ambassadeur « {$ambassadorService->suspendAmbassador(Ambassador::findOrFail($ambassadorId))->full_name} » a été suspendu.");
    }

    public function reactivateAmbassador(int $ambassadorId, AmbassadorService $ambassadorService): void
    {
        if (! $this->allowed(PermissionName::SuspendAmbassador)) {
            return;
        }

        $this->runLifecycleAction(fn (): string => "L'ambassadeur « {$ambassadorService->reactivateAmbassador(Ambassador::findOrFail($ambassadorId))->full_name} » a été réactivé.");
    }

    public function askToDeleteAmbassador(int $ambassadorId): void
    {
        if (! $this->allowed(PermissionName::DeleteAmbassador)) {
            return;
        }

        $this->deletingAmbassadorId = $ambassadorId;
        $this->showDeleteModal = true;
    }

    public function closeDeleteModal(): void
    {
        $this->showDeleteModal = false;
        $this->deletingAmbassadorId = null;
    }

    public function deletingAmbassadorLabel(): ?string
    {
        return $this->deletingAmbassadorId === null
            ? null
            : Ambassador::findOrFail($this->deletingAmbassadorId)->full_name;
    }

    public function confirmDeleteAmbassador(AmbassadorService $ambassadorService): void
    {
        $ambassadorId = $this->deletingAmbassadorId;

        $this->closeDeleteModal();

        if ($ambassadorId === null || ! $this->allowed(PermissionName::DeleteAmbassador)) {
            return;
        }

        $this->runLifecycleAction(function () use ($ambassadorService, $ambassadorId): string {
            $ambassador = Ambassador::findOrFail($ambassadorId);
            $ambassadorService->deleteAmbassador($ambassador);

            return "L'ambassadeur « {$ambassador->full_name} » a été supprimé.";
        });
    }

    /**
     * Run a service call, flashing its returned message, or the business-rule error it raised.
     *
     * @param  callable(): string  $serviceCall
     */
    private function runLifecycleAction(callable $serviceCall): void
    {
        try {
            session()->flash('status', $serviceCall());
        } catch (DomainException $businessRuleViolation) {
            session()->flash('error', $businessRuleViolation->getMessage());
        }
    }

    /**
     * Flash the missing-permission message and return false when the current user lacks the permission.
     */
    private function allowed(PermissionName $permission): bool
    {
        if ($permission->allowedForCurrentUser()) {
            return true;
        }

        session()->flash('error', $permission->unauthorizedMessage());

        return false;
    }

    private function resetAmbassadorForm(): void
    {
        $this->reset([
            'editingAmbassadorId',
            'ambassadorFirstName',
            'ambassadorLastName',
            'ambassadorPhoneNumber',
            'ambassadorWavePhoneNumber',
            'ambassadorCode',
            'contractStartsAt',
            'contractEndsAt',
            'createUserAccount',
            'accountUsername',
            'accountPassword',
        ]);
        $this->resetValidation();
    }

    /**
     * @return array<string, string>
     */
    private function validationAttributes(): array
    {
        return [
            'ambassadorFirstName' => 'prénom',
            'ambassadorLastName' => 'nom',
            'ambassadorPhoneNumber' => 'téléphone',
            'ambassadorWavePhoneNumber' => 'numéro Wave',
            'ambassadorCode' => 'code',
            'contractStartsAt' => 'début du contrat',
            'contractEndsAt' => 'fin du contrat',
            'accountUsername' => 'identifiant',
            'accountPassword' => 'mot de passe',
        ];
    }

    public function render(): View
    {
        PermissionName::ViewAmbassadors->authorizeForCurrentUser();

        return view('livewire.back-office.ambassador-list')->title('Ambassadeurs — Back Office');
    }
}
