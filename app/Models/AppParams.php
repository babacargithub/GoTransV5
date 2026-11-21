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
