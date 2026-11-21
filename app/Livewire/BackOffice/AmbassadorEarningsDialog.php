<?php

namespace App\Livewire\BackOffice;

use App\Enums\AmbassadorEarningStatus;
use App\Enums\PermissionName;
use App\Models\Ambassador;
use App\Services\AmbassadorEarningService;
use App\Services\AmbassadorPerformanceService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The earnings ledger of one ambassador, shown in a dialog of the ambassador list, with the manual approval
 * of pending lines before their waiting period is over (one, or several selected at once). The rules
 * live in {@see AmbassadorEarningService}; this component checks the permission and surfaces the outcome.
 */
class AmbassadorEarningsDialog extends Component
{
    use WithPagination;

    private const MAX_EARNINGS_APPROVED_AT_ONCE = 100;

    #[Locked]
    public int $ambassadorId;

    public string $statusFilter = 'pending';

    /** @var array<int, int|string> ids of the pending lines ticked for approval */
    public array $selectedEarningIds = [];

    public string $approvalReason = '';

    public function mount(int $ambassadorId): void
    {
        $this->ambassadorId = Ambassador::findOrFail($ambassadorId)->id;
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
        $this->selectedEarningIds = [];
    }

    #[Computed]
    public function ambassador(): Ambassador
    {
        return Ambassador::findOrFail($this->ambassadorId);
    }

    #[Computed]
    public function earnings(): LengthAwarePaginator
    {
        return app(AmbassadorPerformanceService::class)->paginateEarnings(
            $this->ambassador,
            AmbassadorEarningStatus::tryFrom($this->statusFilter),
            perPage: 10,
        );
    }

    public function canApproveEarnings(): bool
    {
        return PermissionName::ApproveAmbassadorEarnings->allowedForCurrentUser();
    }

    /**
     * Ticks every pending line of the current page, or clears the selection when they are all ticked.
     */
    public function toggleAllPendingOfPage(): void
    {
        $pendingIdsOfPage = $this->earnings->getCollection()
            ->filter(fn ($earning): bool => $earning->status === AmbassadorEarningStatus::Pending)
            ->pluck('id')
            ->all();

        $this->selectedEarningIds = count(array_diff($pendingIdsOfPage, array_map('intval', $this->selectedEarningIds))) === 0
            ? []
            : $pendingIdsOfPage;
    }

    public function approveSelectedEarnings(AmbassadorEarningService $earningService): void
    {
        if (! $this->canApproveEarnings()) {
            session()->flash('error', PermissionName::ApproveAmbassadorEarnings->unauthorizedMessage());

            return;
        }

        $validated = $this->validate([
            'selectedEarningIds' => ['required', 'array', 'min:1', 'max:'.self::MAX_EARNINGS_APPROVED_AT_ONCE],
            'selectedEarningIds.*' => ['integer'],
            'approvalReason' => ['required', 'string', 'min:5', 'max:255'],
        ], attributes: [
            'selectedEarningIds' => 'gains sélectionnés',
            'approvalReason' => "motif de l'approbation",
        ]);

        $approvedEarningsCount = $earningService->approveEarningsEarly(
            $this->ambassador,
            array_map('intval', $validated['selectedEarningIds']),
            auth()->user(),
            $validated['approvalReason'],
        );

        $this->reset(['selectedEarningIds', 'approvalReason']);
        unset($this->earnings);

        session()->flash('status', $approvedEarningsCount === 0
            ? 'Aucun gain en attente à approuver dans la sélection.'
            : "{$approvedEarningsCount} gain(s) approuvé(s).");
    }

    public function render(): View
    {
        return view('livewire.back-office.ambassador-earnings-dialog');
    }
}
