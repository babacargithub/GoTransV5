<?php

namespace App\Livewire\BackOffice;

use App\Enums\AmbassadorEarningType;
use App\Enums\AmbassadorStatus;
use App\Enums\PermissionName;
use App\Models\Ambassador;
use App\Models\AmbassadorRewardRule;
use App\Models\User;
use App\Services\AmbassadorEarningService;
use App\Services\AmbassadorService;
use App\Services\NotificationService;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

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

    public bool $showEarningsModal = false;

    public ?int $earningsAmbassadorId = null;

    public bool $showRewardRulesModal = false;

    public ?int $rewardRulesAmbassadorId = null;

    public bool $showPromotionModal = false;

    public ?int $promotionAmbassadorId = null;

    public string $promotionType = '';

    public string $promotionNote = '';

    public string $promotionProofUrl = '';

    /** Identifies one submission of the form, so a double click records the promotion only once. */
    public string $promotionSubmissionToken = '';

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
     * @return array{create: bool, createAccount: bool, edit: bool, approve: bool, suspend: bool, delete: bool, recordPromotion: bool}
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
            'recordPromotion' => PermissionName::RecordAmbassadorPromotionRewards->allowedForCurrentUser(),
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

        $ambassador = Ambassador::findOrFail($ambassadorId);

        $this->approvingAmbassadorId = $ambassador->id;
        $this->contractStartsAt = today()->toDateString();
        $this->contractEndsAt = today()->addYear()->toDateString();
        $this->resetValidation();

        $this->createUserAccount = $ambassador->user_account_id === null && $this->allowed(PermissionName::CreateUser);
        $this->accountUsername = $this->createUserAccount ? $this->suggestUsernameFor($ambassador) : '';
        $this->accountPassword = $this->createUserAccount ? Str::lower(Str::random(8)) : '';
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

        $shouldCreateAccount = $this->createUserAccount && $this->allowed(PermissionName::CreateUser);

        $accountRules = $shouldCreateAccount ? [
            'accountUsername' => ['required', 'string', 'max:190', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('users', 'username')],
            'accountPassword' => ['required', 'string', 'min:8', 'max:255'],
        ] : [];

        $validated = $this->validate([
            ...$accountRules,
            'contractStartsAt' => ['required', 'date'],
            'contractEndsAt' => ['required', 'date', 'after_or_equal:contractStartsAt'],
        ], attributes: $this->validationAttributes());

        $accountCredentials = $shouldCreateAccount
            ? ['username' => $validated['accountUsername'], 'password' => $validated['accountPassword']]
            : null;

        $this->runLifecycleAction(function () use ($ambassadorService, $validated, $accountCredentials): string {
            $ambassador = $ambassadorService->approveAmbassador(
                Ambassador::findOrFail($this->approvingAmbassadorId),
                auth()->user(),
                $validated['contractStartsAt'],
                $validated['contractEndsAt'],
                $accountCredentials,
            );

            try {
                app(NotificationService::class)->notifyAmbassadorOfApproval($ambassador, $accountCredentials);
            } catch (Throwable $smsFailure) {
                report($smsFailure);

                return "L'ambassadeur « {$ambassador->full_name} » a été validé, mais le SMS n'a pas pu être envoyé : communiquez-lui lui-même ses accès.";
            }

            return "L'ambassadeur « {$ambassador->full_name} » a été validé"
                .($accountCredentials !== null ? ', son compte a été créé et ses accès lui ont été envoyés par SMS.' : '.');
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

    /**
     * @return array<string, string> earning type value => French label, for the rewards recorded by hand
     */
    #[Computed]
    public function promotionTypeOptions(): array
    {
        return collect(AmbassadorEarningType::cases())
            ->filter(fn (AmbassadorEarningType $earningType): bool => $earningType->isRecordedManually())
            ->mapWithKeys(fn (AmbassadorEarningType $earningType): array => [$earningType->value => $earningType->label()])
            ->all();
    }

    /**
     * What recording the chosen promotion would pay the ambassador right now, or null when no rule applies.
     */
    public function promotionAmountToPay(): ?int
    {
        $promotionType = AmbassadorEarningType::tryFrom($this->promotionType);

        if ($promotionType === null || $this->promotionAmbassadorId === null) {
            return null;
        }

        return AmbassadorRewardRule::findApplicableRule($promotionType, Ambassador::findOrFail($this->promotionAmbassadorId))?->amount;
    }

    public function promotionAmbassadorLabel(): ?string
    {
        return $this->promotionAmbassadorId === null
            ? null
            : Ambassador::findOrFail($this->promotionAmbassadorId)->full_name;
    }

    public function openPromotionReward(int $ambassadorId): void
    {
        if (! $this->allowed(PermissionName::RecordAmbassadorPromotionRewards)) {
            return;
        }

        $this->reset(['promotionType', 'promotionNote', 'promotionProofUrl']);
        $this->resetValidation();
        $this->promotionAmbassadorId = Ambassador::findOrFail($ambassadorId)->id;
        $this->promotionSubmissionToken = (string) Str::uuid();
        $this->showPromotionModal = true;
    }

    public function closePromotionModal(): void
    {
        $this->showPromotionModal = false;
        $this->promotionAmbassadorId = null;
    }

    public function recordPromotionReward(AmbassadorEarningService $earningService): void
    {
        if (! $this->allowed(PermissionName::RecordAmbassadorPromotionRewards)) {
            return;
        }

        $validated = $this->validate([
            'promotionType' => ['required', Rule::in(array_keys($this->promotionTypeOptions))],
            'promotionNote' => ['nullable', 'string', 'max:150'],
            'promotionProofUrl' => ['nullable', 'url', 'max:500'],
        ], attributes: [
            'promotionType' => 'type de promotion',
            'promotionNote' => 'note',
            'promotionProofUrl' => 'lien de preuve',
        ]);

        $ambassadorId = $this->promotionAmbassadorId;
        $submissionToken = $this->promotionSubmissionToken;

        $this->closePromotionModal();

        if ($ambassadorId === null) {
            return;
        }

        $this->runLifecycleAction(function () use ($earningService, $ambassadorId, $submissionToken, $validated): string {
            $ambassador = Ambassador::findOrFail($ambassadorId);
            $earning = $earningService->recordPromotionReward(
                ambassador: $ambassador,
                promotionType: AmbassadorEarningType::from($validated['promotionType']),
                submissionToken: $submissionToken,
                note: filled($validated['promotionNote']) ? $validated['promotionNote'] : null,
                proofUrl: filled($validated['promotionProofUrl']) ? $validated['promotionProofUrl'] : null,
                recordedBy: auth()->user(),
            );

            return "{$earning->type->label()} enregistré pour {$ambassador->full_name} : {$earning->amount} FCFA.";
        });
    }

    public function openEarnings(int $ambassadorId): void
    {
        $this->earningsAmbassadorId = Ambassador::findOrFail($ambassadorId)->id;
        $this->showEarningsModal = true;
    }

    public function closeEarningsModal(): void
    {
        $this->showEarningsModal = false;
        $this->earningsAmbassadorId = null;
    }

    public function openRewardRules(int $ambassadorId): void
    {
        $this->rewardRulesAmbassadorId = Ambassador::findOrFail($ambassadorId)->id;
        $this->showRewardRulesModal = true;
    }

    public function closeRewardRulesModal(): void
    {
        $this->showRewardRulesModal = false;
        $this->rewardRulesAmbassadorId = null;
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

    /**
     * A free username built from the ambassador's first name, suffixed with digits until it is unique.
     */
    private function suggestUsernameFor(Ambassador $ambassador): string
    {
        $baseUsername = Str::slug($ambassador->first_name, '') ?: 'ambassadeur';
        $candidateUsername = $baseUsername;

        while (User::where('username', $candidateUsername)->exists()) {
            $candidateUsername = $baseUsername.random_int(10, 99);
        }

        return $candidateUsername;
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
