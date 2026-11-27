<?php

namespace App\Livewire\Amicale\Concerns;

use App\Models\Amicale;
use Livewire\Attributes\Computed;

/**
 * Gives an amicale space page the amicale behind the logged-in account. Every query of these pages
 * starts from this amicale, so an amicale can never see another one's data.
 */
trait ResolvesLoggedInAmicale
{
    #[Computed]
    public function amicale(): Amicale
    {
        return Amicale::query()
            ->where('user_account_id', auth()->id())
            ->firstOrFail();
    }
}
