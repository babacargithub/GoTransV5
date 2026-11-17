<?php

namespace App\Livewire\BackOffice;

use App\Enums\AmbassadorPayoutStatus;
use App\Enums\AmbassadorStatus;
use App\Enums\PermissionName;
use App\Models\Ambassador;
use App\Models\AmbassadorPayout;
use App\Services\AmbassadorPayoutRunService;
use App\Services\AmbassadorPerformanceService;
use App\Services\EarningPayoutService;
use App\Services\WaveService;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

/**
 * Back office "Commissions ambassadeurs": who can be paid now, every payout with its status and failure
 * reason, and the two manual money-out actions (pay an ambassador, send a failed payout again). All business
 * rules live in {@see AmbassadorPayoutRunService} and {@see WaveService}; this component checks the permission
 * and surfaces the outcome.
 */
#[Layout('components.layouts.back-office')]
class AmbassadorPayoutList extends Component
{
    use WithPagination;

    #[Url(as: 'statut')]
    public string $statusFilter = '';

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function payouts(): LengthAwarePaginator
    {
        return app(AmbassadorPerformanceService::class)->paginatePayoutsForBackOffice(
            AmbassadorPayoutStatus::tryFrom($this->statusFilter),
        );
    }

    /**
     * @return array<string, string> status value => French label
     */
    #[Computed]
    public function statusOptions(): array
    {
        return collect(AmbassadorPayoutStatus::cases())
            ->mapWithKeys(fn (AmbassadorPayoutStatus $status): array => [$status->value => $status->label()])
            ->all();
    }

    #[Computed]
    public function minimumPayoutAmount(): int
    {
        return app(AmbassadorPayoutRunService::class)->minimumPayoutAmount();
    }

    /**
     * The active or suspended ambassadors who have something payable, with the amount and whether it reached the minimum.
     * An ambassador whose earnings hold an invalid amount is listed as blocked instead of hiding the error.
     *
     * @return list<array{ambassador: Ambassador, payableAmount: int|null, canBePaid: bool}>
     */
    #[Computed]
    public function ambassadorsWithPayableBalance(): array
    {
        $earningPayoutService = app(EarningPayoutService::class);

        return Ambassador::query()
            ->whereIn('status', AmbassadorStatus::payableStatuses())
            ->whereHas('earnings', fn ($earningsQuery) => $earningsQuery->payable())
            ->orderBy('first_name')
            ->get()
            ->map(function (Ambassador $ambassador) use ($earningPayoutService): array {
                try {
                    $payableAmount = $earningPayoutService->calculatePayableAmount($ambassador);
                } catch (DomainException) {
                    return ['ambassador' => $ambassador, 'payableAmount' => null, 'canBePaid' => false];
                }

                return [
                    'ambassador' => $ambassador,
                    'payableAmount' => $payableAmount,
                    'canBePaid' => $payableAmount >= max(1, $this->minimumPayoutAmount),
                ];
            })
            ->all();
    }

    /**
     * @return array{pay: bool}
     */
    #[Computed]
    public function abilities(): array
    {
        return ['pay' => PermissionName::PayAmbassadors->allowedForCurrentUser()];
    }

    public function payAmbassador(int $ambassadorId, AmbassadorPayoutRunService $ambassadorPayoutRunService): void
    {
        if (! $this->allowed(PermissionName::PayAmbassadors)) {
            return;
        }

        try {
            $payout = $ambassadorPayoutRunService->payAmbassador(Ambassador::findOrFail($ambassadorId), auth()->id());
        } catch (DomainException $businessRuleViolation) {
            session()->flash('error', $businessRuleViolation->getMessage());

            return;
        } catch (Throwable $throwable) {
            Log::error("Manual payout failed for ambassador {$ambassadorId}: {$throwable->getMessage()}", ['exception' => $throwable]);
            session()->flash('error', 'Le paiement a échoué avant tout envoi : aucune somme n\'a été envoyée.');

            return;
        }

        $this->flashPayoutOutcome($payout);
    }

    public function retryPayout(int $payoutId, WaveService $waveService): void
    {
        if (! $this->allowed(PermissionName::PayAmbassadors)) {
            return;
        }

        $payout = AmbassadorPayout::findOrFail($payoutId);

        if (! $payout->canBeSent()) {
            session()->flash('error', 'Ce paiement ne peut pas être renvoyé (déjà payé, accepté par Wave ou tentatives épuisées).');

            return;
        }

        try {
            $payout = $waveService->pay($payout);
        } catch (Throwable $throwable) {
            Log::error("Manual retry of payout {$payoutId} failed: {$throwable->getMessage()}", ['exception' => $throwable]);
            session()->flash('error', 'Le renvoi a échoué. Vérifiez le statut du paiement avant de réessayer.');

            return;
        }

        $this->flashPayoutOutcome($payout);
    }

    private function flashPayoutOutcome(AmbassadorPayout $payout): void
    {
        $amountLabel = number_format($payout->amount, 0, ',', ' ');

        match ($payout->status) {
            AmbassadorPayoutStatus::Success => session()->flash('status', "{$amountLabel} FCFA payés."),
            AmbassadorPayoutStatus::Pending => session()->flash('status', "{$amountLabel} FCFA envoyés : en attente de confirmation par Wave."),
            AmbassadorPayoutStatus::Failed => session()->flash('error', "Le paiement de {$amountLabel} FCFA a échoué : {$payout->failure_reason}"),
        };
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

    public function render(): View
    {
        PermissionName::ViewAmbassadors->authorizeForCurrentUser();

        return view('livewire.back-office.ambassador-payout-list')->title('Commissions ambassadeurs — Back Office');
    }
}
