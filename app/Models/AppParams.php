<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppParams extends Model
{
    //
    protected $fillable = [
        'data',
    ];

    protected $table = 'app_params';

    protected $casts = [
        'data' => 'array',
    ];

    public function getBusAgentDefaultNumber()
    {

        return $this->data['bus_agent_default_number'] ?? 771273535;
    }

    /**
     * Amount (FCFA) paid to an ambassador for each new customer they acquire.
     */
    public function getAmbassadorAcquisitionAmount(): int
    {
        return (int) ($this->data['ambassador_acquisition_amount'] ?? 500);
    }

    /**
     * One-time amount (FCFA) paid to a sponsor when a sponsored ambassador reaches the threshold.
     */
    public function getAmbassadorSponsorshipBonusAmount(): int
    {
        return (int) ($this->data['ambassador_sponsorship_bonus_amount'] ?? 2000);
    }

    /**
     * Number of acquired customers a sponsored ambassador must reach to trigger the sponsor's bonus.
     */
    public function getAmbassadorSponsorshipThreshold(): int
    {
        return (int) ($this->data['ambassador_sponsorship_threshold'] ?? 15);
    }

    /**
     * Minimum approved balance (FCFA) an ambassador needs before a Wave payout can be made.
     */
    public function getAmbassadorMinimumPayoutAmount(): int
    {
        return (int) ($this->data['ambassador_minimum_payout_amount'] ?? 1000);
    }
}
