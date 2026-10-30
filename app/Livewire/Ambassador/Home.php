<?php

namespace App\Livewire\Ambassador;

use App\Models\Ambassador;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Ambassador space home page (minimal for now): who they are, their code and contract status.
 */
#[Layout('components.layouts.ambassador')]
class Home extends Component
{
    #[Computed]
    public function ambassador(): Ambassador
    {
        return Ambassador::query()
            ->where('user_account_id', auth()->id())
            ->firstOrFail();
    }

    public function render(): View
    {
        return view('livewire.ambassador.home')->title('Mon espace ambassadeur — Globe One Transport');
    }
}
