<?php

namespace App\Livewire\Ambassador\Concerns;

use App\Models\Ambassador;
use Livewire\Attributes\Computed;

/**
 * Gives an ambassador space page the ambassador behind the logged-in account. Every query of these pages
 * starts from this ambassador, so an ambassador can never see another one's data.
 */
trait ResolvesLoggedInAmbassador
{
    #[Computed]
    public function ambassador(): Ambassador
    {
        return Ambassador::query()
            ->where('user_account_id', auth()->id())
            ->firstOrFail();
    }
}
