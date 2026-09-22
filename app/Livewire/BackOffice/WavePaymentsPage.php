<?php

namespace App\Livewire\BackOffice;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Back office "Paiements Wave" page.
 *
 * Placeholder for now — the Wave balance / transactions / refund screens are not
 * built yet. Kept as a real route so the Finances menu item works.
 */
#[Layout('components.layouts.back-office')]
class WavePaymentsPage extends Component
{
    public function render(): View
    {
        return view('livewire.back-office.wave-payments-page')->title('Paiements Wave — Back Office');
    }
}
