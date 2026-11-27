<?php

namespace App\Livewire\Amicale;

use App\Livewire\Amicale\Concerns\ResolvesLoggedInAmicale;
use App\Services\AmicalePerformanceService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Amicale space home page: what the amicale has earned and the bookings its members made on its website.
 */
#[Layout('components.layouts.amicale')]
class Home extends Component
{
    use ResolvesLoggedInAmicale;
    use WithPagination;

    #[Computed]
    public function balance(): int
    {
        return app(AmicalePerformanceService::class)->getBalance($this->amicale);
    }

    #[Computed]
    public function paidBookingsCount(): int
    {
        return app(AmicalePerformanceService::class)->countPaidBookings($this->amicale);
    }

    /**
     * Public address of the amicale's website, to share with its members.
     */
    #[Computed]
    public function websiteUrl(): string
    {
        return request()->getScheme().'://'.$this->amicale->slug.'.'.config('app.public_website_domain');
    }

    #[Computed]
    public function bookings(): LengthAwarePaginator
    {
        return app(AmicalePerformanceService::class)->paginateBookings($this->amicale);
    }

    public function render(): View
    {
        return view('livewire.amicale.home')->title('Mon espace amicale — Globe One Transport');
    }
}
