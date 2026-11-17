<?php

namespace App\Services;

use App\Enums\AmbassadorStatus;
use App\Models\Ambassador;
use App\Models\AmbassadorPayout;
use App\Models\AppParams;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Turns what the company owes an ambassador into a Wave payment: checks the ambassador and the minimum
 * amount, records the debt through EarningPayoutService, then sends it through WaveService. Shared by the
 * manual back-office action so the guards live in one place.
 * Callers are responsible for checking the permission to pay before invoking it.
 */
class AmbassadorPayoutRunService
{
    public function __construct(
        private readonly EarningPayoutService $earningPayoutService,
        private readonly WaveService $waveService,
    ) {}

    /**
     * Pays one ambassador everything that is payable to them. Active and suspended ambassadors are paid (earned money is owed even after a suspension), and only when
     * the payable amount reached the minimum payout amount.
     *
     * @throws DomainException when the ambassador cannot be paid now (not payable status, nothing payable, below the minimum)
     * @throws Throwable when the debt could not be recorded; nothing was sent to Wave in that case
     */
    public function payAmbassador(Ambassador $ambassador, ?int $requestedByUserId = null): AmbassadorPayout
    {
        if (! in_array($ambassador->status, AmbassadorStatus::payableStatuses(), true)) {
            throw new DomainException("L'ambassadeur {$ambassador->full_name} ne peut pas être payé (statut : {$ambassador->status->label()}).");
        }

        $payableAmount = $this->earningPayoutService->calculatePayableAmount($ambassador);
        $minimumPayoutAmount = $this->minimumPayoutAmount();

        if ($payableAmount <= 0) {
            throw new DomainException("Rien à payer à {$ambassador->full_name}.");
        }

        if ($payableAmount < $minimumPayoutAmount) {
            throw new DomainException("Le solde de {$ambassador->full_name} ({$payableAmount} FCFA) est inférieur au minimum de {$minimumPayoutAmount} FCFA.");
        }

        $payout = $this->earningPayoutService->createPayoutForPayableEarnings($ambassador, $requestedByUserId);

        if ($payout === null) {
            throw new DomainException("Rien à payer à {$ambassador->full_name}.");
        }

        return $this->waveService->pay($payout);
    }

    /**
     * Ambassadors are paid on the 15th and on the last day of each month.
     */
    public function isPayoutDay(Carbon $date): bool
    {
        return $date->day === 15 || $date->isLastOfMonth();
    }

    /**
     * What the manager will be able to pay today: the active or suspended ambassadors whose payable balance reached the minimum.
     * An ambassador with an invalid earning is logged and left out, so one bad record never hides the others.
     *
     * @return array{ambassadors_count: int, total_amount: int}
     */
    public function summarizePayableAmounts(): array
    {
        $minimumPayoutAmount = $this->minimumPayoutAmount();
        $summary = ['ambassadors_count' => 0, 'total_amount' => 0];

        Ambassador::query()
            ->whereIn('status', AmbassadorStatus::payableStatuses())
            ->whereHas('earnings', fn ($earningsQuery) => $earningsQuery->payable())
            ->each(function (Ambassador $ambassador) use ($minimumPayoutAmount, &$summary): void {
                try {
                    $payableAmount = $this->earningPayoutService->calculatePayableAmount($ambassador);
                } catch (InvalidArgumentException $invalidEarning) {
                    Log::error("Ambassador {$ambassador->id} left out of the payout summary: {$invalidEarning->getMessage()}");

                    return;
                }

                if ($payableAmount > 0 && $payableAmount >= $minimumPayoutAmount) {
                    $summary['ambassadors_count']++;
                    $summary['total_amount'] += $payableAmount;
                }
            });

        return $summary;
    }

    public function minimumPayoutAmount(): int
    {
        return (AppParams::query()->first() ?? new AppParams(['data' => []]))->getAmbassadorMinimumPayoutAmount();
    }
}
