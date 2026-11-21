<?php

namespace App\Livewire\BackOffice;

use App\Enums\AmbassadorEarningType;
use App\Enums\AmbassadorStatus;
use App\Enums\PermissionName;
use App\Models\Ambassador;
use App\Models\AmbassadorRewardRule;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Back office "Règles de récompense": what each kind of ambassador reward pays, for whom and when. Rules
 * can be paused, limited to a period and dedicated to one ambassador. Earnings already created keep
 * the amount they were given, so editing a rule only affects future rewards.
 *
 * Used as a full page for every rule, and embedded in the ambassador list with an ambassador id, in which
 * case it only lists, creates and changes the rules dedicated to that ambassador.
 */
#[Layout('components.layouts.back-office')]
class AmbassadorRewardRuleList extends Component
{
    /** Earning types a rule can be written for. */
    private const REWARDABLE_EARNING_TYPES = [
        AmbassadorEarningType::Acquisition,
        AmbassadorEarningType::ExistingCustomerBooking,
        AmbassadorEarningType::SponsorshipBonus,
        AmbassadorEarningType::AdvertisementShare,
        AmbassadorEarningType::VideoContent,
    ];

    /** Set when the component is embedded for one ambassador; null on the full page. */
    #[Locked]
    public ?int $restrictedAmbassadorId = null;

    public bool $showRuleModal = false;

    public ?int $editingRuleId = null;

    public string $ruleEarningType = '';

    public ?int $ruleAmount = null;

    public string $ruleAmbassadorId = '';

    public string $ruleStartsAt = '';

    public string $ruleEndsAt = '';

    public bool $ruleIsEnabled = false;

    public bool $showDeleteModal = false;

    public ?int $deletingRuleId = null;

    public function mount(?int $ambassadorId = null): void
    {
        $this->restrictedAmbassadorId = $ambassadorId;
    }

    #[Computed]
    public function restrictedAmbassador(): ?Ambassador
    {
        return $this->restrictedAmbassadorId === null
            ? null
            : Ambassador::findOrFail($this->restrictedAmbassadorId);
    }

    /**
     * @return Collection<int, AmbassadorRewardRule>
     */
    #[Computed]
    public function rules(): Collection
    {
        return $this->manageableRulesQuery()
            ->with('ambassador')
            ->orderBy('earning_type')
            ->orderByRaw('ambassador_id is not null')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return array<string, string> earning type value => French label
     */
    #[Computed]
    public function earningTypeOptions(): array
    {
        return collect(self::REWARDABLE_EARNING_TYPES)
            ->mapWithKeys(fn (AmbassadorEarningType $earningType): array => [$earningType->value => $earningType->label()])
            ->all();
    }

    /**
     * @return array<int, string> ambassador id => full name, for the ambassadors that can still attribute customers
     */
    #[Computed]
    public function ambassadorOptions(): array
    {
        return Ambassador::query()
            ->where('status', AmbassadorStatus::Active)
            ->orderBy('first_name')
            ->get()
            ->mapWithKeys(fn (Ambassador $ambassador): array => [$ambassador->id => $ambassador->full_name])
            ->all();
    }

    public function canManageRules(): bool
    {
        return PermissionName::ManageAmbassadorRewardRules->allowedForCurrentUser();
    }

    public function openCreateRule(): void
    {
        if (! $this->allowedToManageRules()) {
            return;
        }

        $this->resetRuleForm();
        $this->showRuleModal = true;
    }

    public function openEditRule(int $ruleId): void
    {
        if (! $this->allowedToManageRules()) {
            return;
        }

        $rule = $this->manageableRulesQuery()->findOrFail($ruleId);

        $this->resetRuleForm();
        $this->editingRuleId = $rule->id;
        $this->ruleEarningType = $rule->earning_type->value;
        $this->ruleAmount = $rule->amount;
        $this->ruleAmbassadorId = (string) ($rule->ambassador_id ?? '');
        $this->ruleStartsAt = $rule->starts_at?->format('Y-m-d\TH:i') ?? '';
        $this->ruleEndsAt = $rule->ends_at?->format('Y-m-d\TH:i') ?? '';
        $this->ruleIsEnabled = $rule->is_enabled;
        $this->showRuleModal = true;
    }

    public function closeRuleModal(): void
    {
        $this->showRuleModal = false;
        $this->resetRuleForm();
    }

    public function saveRule(): void
    {
        if (! $this->allowedToManageRules()) {
            return;
        }

        $validated = $this->validate([
            'ruleEarningType' => ['required', Rule::in(array_keys($this->earningTypeOptions))],
            'ruleAmount' => ['required', 'integer', 'min:1', 'max:100000'],
            'ruleAmbassadorId' => ['nullable', Rule::exists('ambassadors', 'id')],
            'ruleStartsAt' => ['nullable', 'date'],
            'ruleEndsAt' => ['nullable', 'date', 'after:ruleStartsAt'],
            'ruleIsEnabled' => ['boolean'],
        ], attributes: [
            'ruleEarningType' => 'type de récompense',
            'ruleAmount' => 'montant',
            'ruleAmbassadorId' => 'ambassadeur',
            'ruleStartsAt' => 'début',
            'ruleEndsAt' => 'fin',
        ]);

        $ruleAmbassadorId = $this->restrictedAmbassadorId
            ?? (filled($validated['ruleAmbassadorId']) ? (int) $validated['ruleAmbassadorId'] : null);

        $ruleAttributes = [
            'earning_type' => $validated['ruleEarningType'],
            'amount' => $validated['ruleAmount'],
            'ambassador_id' => $ruleAmbassadorId,
            'starts_at' => filled($validated['ruleStartsAt']) ? $validated['ruleStartsAt'] : null,
            'ends_at' => filled($validated['ruleEndsAt']) ? $validated['ruleEndsAt'] : null,
            'is_enabled' => $validated['ruleIsEnabled'],
        ];

        if ($this->editingRuleId === null) {
            AmbassadorRewardRule::create($ruleAttributes);
        } else {
            $this->manageableRulesQuery()->findOrFail($this->editingRuleId)->update($ruleAttributes);
        }

        session()->flash('status', $this->editingRuleId ? 'La règle a été modifiée.' : 'La règle a été créée.');
        $this->closeRuleModal();
    }

    public function toggleRule(int $ruleId): void
    {
        if (! $this->allowedToManageRules()) {
            return;
        }

        $rule = $this->manageableRulesQuery()->findOrFail($ruleId);
        $rule->update(['is_enabled' => ! $rule->is_enabled]);

        session()->flash('status', $rule->is_enabled ? 'La règle est active.' : 'La règle est en pause.');
    }

    public function askToDeleteRule(int $ruleId): void
    {
        if (! $this->allowedToManageRules()) {
            return;
        }

        $this->deletingRuleId = $ruleId;
        $this->showDeleteModal = true;
    }

    public function closeDeleteModal(): void
    {
        $this->showDeleteModal = false;
        $this->deletingRuleId = null;
    }

    public function confirmDeleteRule(): void
    {
        $ruleId = $this->deletingRuleId;

        $this->closeDeleteModal();

        if ($ruleId === null || ! $this->allowedToManageRules()) {
            return;
        }

        $this->manageableRulesQuery()->whereKey($ruleId)->delete();

        session()->flash('status', 'La règle a été supprimée.');
    }

    public function render(): View
    {
        return view('livewire.back-office.ambassador-reward-rule-list')->title('Règles de récompense — Back Office');
    }

    /**
     * The rules this component may show and change: every rule on the full page, only the ones dedicated to
     * the ambassador when embedded for one.
     *
     * @return Builder<AmbassadorRewardRule>
     */
    private function manageableRulesQuery(): Builder
    {
        return AmbassadorRewardRule::query()
            ->when($this->restrictedAmbassadorId !== null, fn ($rulesQuery) => $rulesQuery->where('ambassador_id', $this->restrictedAmbassadorId));
    }

    private function allowedToManageRules(): bool
    {
        if ($this->canManageRules()) {
            return true;
        }

        session()->flash('error', PermissionName::ManageAmbassadorRewardRules->unauthorizedMessage());

        return false;
    }

    private function resetRuleForm(): void
    {
        $this->reset(['editingRuleId', 'ruleEarningType', 'ruleAmount', 'ruleAmbassadorId', 'ruleStartsAt', 'ruleEndsAt', 'ruleIsEnabled']);
        $this->resetValidation();
    }
}
