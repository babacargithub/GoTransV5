<?php

namespace App\Livewire\BackOffice;

use App\Models\Caisse;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Total caisse balance (sum across every caisse) shown in the back-office
 * header, next to the global search, on every page. Read-only — no
 * mutations happen here.
 */
class TotalCaisseBalance extends Component
{
    #[Computed]
    public function totalBalance(): int
    {
        return (int) Caisse::sum('balance');
    }

    public function render(): View
    {
        return view('livewire.back-office.total-caisse-balance');
    }
}
