<?php

namespace App\Livewire\BackOffice;

use App\Enums\PermissionName;
use App\Livewire\BackOffice\Concerns\ManagesProfitReport;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Back office "Revenus" page (Finances): profit report (revenue − expenses) over a date range,
 * broken down by account and expense type — see ManagesProfitReport / ProfitService.
 *
 * Restricted to full-access users, like the Finances pages.
 */
#[Layout('components.layouts.back-office')]
class ProfitReportPage extends Component
{
    use ManagesProfitReport;

    public function mount(): void
    {
        PermissionName::FullAccess->authorizeForCurrentUser();
    }

    public function render(): View
    {
        return view('livewire.back-office.profit-report-page')->title('Revenus — Back Office');
    }
}
