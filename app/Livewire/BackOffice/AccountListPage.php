<?php

namespace App\Livewire\BackOffice;

use App\Livewire\BackOffice\Concerns\FlashesStatusAndGuardsPermissions;
use App\Livewire\BackOffice\Concerns\ManagesAccounts;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Back office "Comptes" page (Finances): the generic Account ledger shown as
 * cards, paired with Caisse via the SUM(accounts) == SUM(caisses) invariant
 * enforced by AccountService.
 */
#[Layout('components.layouts.back-office')]
class AccountListPage extends Component
{
    use FlashesStatusAndGuardsPermissions;
    use ManagesAccounts;

    public function render(): View
    {
        return view('livewire.back-office.account-list-page')->title('Comptes — Back Office');
    }
}
