<?php

namespace App\Services;

use App\Enums\AmbassadorEarningType;
use App\Models\Ambassador;
use App\Models\AmbassadorRewardRule;
use App\Models\AppParams;

/**
 * Replaces the {{placeholders}} of the contract template with the values that apply to one ambassador (identity,
 * contract dates, reward amounts that apply to them, program thresholds). A placeholder that is not known is left
 * untouched, so a typo in the template stays visible instead of silently disappearing.
 */
class AmbassadorContractTextRenderer
{
    private const MISSING_VALUE_TEXT = '[à définir]';

    /**
     * @param  string  $contractTemplate  the markdown of the contract, with {{placeholders}}
     */
    public function render(string $contractTemplate, Ambassador $ambassador): string
    {
        $placeholderValues = $this->placeholderValuesFor($ambassador);

        return (string) preg_replace_callback(
            '/\{\{\s*([a-z_]+)\s*\}\}/',
            fn (array $match): string => $placeholderValues[$match[1]] ?? $match[0],
            $contractTemplate,
        );
    }

    /**
     * @return array<string, string>
     */
    private function placeholderValuesFor(Ambassador $ambassador): array
    {
        $appParams = AppParams::query()->first() ?? new AppParams(['data' => []]);

        return [
            'ambassador_full_name' => $ambassador->full_name,
            'ambassador_phone_number' => (string) $ambassador->phone_number,
            'contract_start' => $ambassador->contract_starts_at?->format('d/m/Y') ?? self::MISSING_VALUE_TEXT,
            'contract_end' => $ambassador->contract_ends_at?->format('d/m/Y') ?? self::MISSING_VALUE_TEXT,
            'acquisition_earning' => $this->formatAmount($this->applicableRewardAmount(AmbassadorEarningType::Acquisition, $ambassador)),
            'promotion_earning' => $this->formatAmount($this->applicableRewardAmount(AmbassadorEarningType::SponsorshipBonus, $ambassador)),
            'sponsorship_bonus' => (string) $appParams->getAmbassadorSponsorshipThreshold(),
            'minimum_amount' => $this->formatAmount($appParams->getAmbassadorMinimumPayoutAmount()),
        ];
    }

    private function applicableRewardAmount(AmbassadorEarningType $earningType, Ambassador $ambassador): ?int
    {
        return AmbassadorRewardRule::findApplicableRule($earningType, $ambassador)?->amount;
    }

    private function formatAmount(?int $amount): string
    {
        return $amount === null ? self::MISSING_VALUE_TEXT : number_format($amount, 0, ',', ' ');
    }
}
