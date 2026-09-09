<?php

namespace App\Livewire\Website;

use App\Models\Booking;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Public booking page for a group of bookings created from the student booking form.
 *
 * Addressed by the group's shared UUID (see the add_uuid_to_bookings migration) so the numeric
 * group_id is never exposed. Mirrors the mobile app's ShowMultipleBooking screen.
 */
class BookingGroupShow extends Component
{
    public string $uuid;

    public function mount(string $uuid): void
    {
        $this->uuid = $uuid;

        abort_if($this->bookings->isEmpty(), 404);
    }

    /**
     * @return Collection<int, Booking>
     */
    #[Computed]
    public function bookings(): Collection
    {
        return Booking::query()
            ->where('uuid', $this->uuid)
            ->with(['customer', 'depart', 'bus', 'point_dep', 'destination', 'seat', 'ticket'])
            ->orderBy('id')
            ->get();
    }

    public function render(): View
    {
        return view('livewire.website.booking-group-show')
            ->layout('components.layouts.website', [
                'title' => 'Ma réservation | Globe One Transport',
                'description' => 'Suivez votre réservation de bus Globe One Transport.',
                'robots' => 'noindex, nofollow',
            ]);
    }
}
