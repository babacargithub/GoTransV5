<?php

namespace App\Livewire\Ambassador;

use App\Data\Ambassador\AmbassadorBalanceDTO;
use App\Livewire\Ambassador\Concerns\ResolvesLoggedInAmbassador;
use App\Services\AmbassadorPerformanceService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Ambassador space home page: who they are, their account balance, their code and contract status.
 */
#[Layout('components.layouts.ambassador')]
class Home extends Component
{
    use ResolvesLoggedInAmbassador;
    use WithPagination;

    /** Stands for the ambassador's share link inside the message they send. */
    public const SHARE_LINK_PLACEHOLDER = '{{lien}}';

    public const DEFAULT_SHARE_MESSAGE = 'Réserve ton billet de bus Globe One Transport avec mon lien : '.self::SHARE_LINK_PLACEHOLDER;

    #[Computed]
    public function accountBalance(): AmbassadorBalanceDTO
    {
        return app(AmbassadorPerformanceService::class)->getAccountBalance($this->ambassador);
    }

    /**
     * Public website link carrying the ambassador's code, to share with friends.
     */
    #[Computed]
    public function shareUrl(): string
    {
        return route('website.home', ['ref' => strtolower($this->ambassador->code)]);
    }

    /**
     * Whether the ambassador signed the current contract version: without it their code attributes no customer
     * and their earnings are not paid.
     */
    #[Computed]
    public function hasSignedContract(): bool
    {
        return $this->ambassador->hasSignedContract();
    }

    #[Computed]
    public function acquiredCustomersCount(): int
    {
        return app(AmbassadorPerformanceService::class)->countAcquiredCustomers($this->ambassador);
    }

    /**
     * The bookings made with the ambassador's code, paginated.
     */
    #[Computed]
    public function referredBookings(): LengthAwarePaginator
    {
        return app(AmbassadorPerformanceService::class)->paginateReferredBookings($this->ambassador);
    }

    public function render(): View
    {
        return view('livewire.ambassador.home')->title('Mon espace ambassadeur — Globe One Transport');
    }
}
