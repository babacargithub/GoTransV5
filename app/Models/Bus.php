<?php

namespace App\Models;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bus extends Model
{
    //
    protected $table = 'buses';

    protected $fillable = [
        'name',
        'depart_id',
        'closed',
        'closed_at',
        'deleted_at',
        'nombre_place',
        'ticket_price',
        'gp_ticket_price',
        'vehicule_id',
        'visibilite',
        'itinerary_id',
        'agent_numbers',

    ];

    public function depart(): BelongsTo
    {
        return $this->belongsTo(Depart::class);
    }

    public function vehicule(): BelongsTo
    {
        return $this->belongsTo(Vehicule::class);

    }

    public function seats(): HasMany
    {
        return $this->hasMany(BusSeat::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);

    }

    public function heuresDeparts(): HasMany
    {
        return $this->hasMany(HeureDepart::class);
    }

    /**
     * Restricts a bus's seats to those with no active (non-cancelled) booking.
     */
    private function freeSeatsConstraint(): Closure
    {
        return fn ($seatsQuery) => $seatsQuery->whereNotExists(function ($query) {
            $query->select('id')
                ->from('bookings')
                ->whereColumn('bookings.seat_id', 'bus_seats.id')
                ->whereNull('bookings.deleted_at');
        });
    }

    /**
     * Loads, in the same query as the buses, the figures the départ list shows for each bus, so rendering
     * a list of buses costs no per-bus queries and never hydrates the bookings themselves. seatsLeft(),
     * numberOfBookings(), numberOfBookedSeats() and numberOfTicketsSold() read these values when present.
     * They are a snapshot: only use this scope for read-only listings.
     *
     * @param  Builder<Bus>  $query
     */
    public function scopeWithDepartListCounts(Builder $query): void
    {
        $query->withCount([
            'bookings',
            'bookings as booked_seats_count' => fn ($bookings) => $bookings->whereNotNull('seat_id'),
            'bookings as tickets_sold_count' => fn ($bookings) => $bookings->whereNotNull('ticket_id'),
            'seats as seats_left_count' => $this->freeSeatsConstraint(),
            'seats as marked_booked_seats_count' => fn ($seats) => $seats->where('booked', true),
        ]);
    }

    /**
     * Lean variant of withDepartListCounts() for DepartController@bookingsCount (back-office sidebar, shown on
     * every page): only the three figures it reads, skipping the seat-availability count, which is the costliest.
     *
     * @param  Builder<Bus>  $query
     */
    public function scopeWithBookingStatsCounts(Builder $query): void
    {
        $query->withCount([
            'bookings',
            'bookings as tickets_sold_count' => fn ($bookings) => $bookings->whereNotNull('ticket_id'),
            'seats as marked_booked_seats_count' => fn ($seats) => $seats->where('booked', true),
        ]);
    }

    /**
     * Seats whose own `booked` flag is set (not the same as seats held by an active booking, see seatsLeft()).
     */
    public function numberOfSeatsMarkedBooked(): int
    {
        return array_key_exists('marked_booked_seats_count', $this->attributes)
            ? (int) $this->attributes['marked_booked_seats_count']
            : $this->seats()->where('booked', true)->count();
    }

    public function seatsLeft(): int
    {
        if (array_key_exists('seats_left_count', $this->attributes)) {
            return (int) $this->attributes['seats_left_count'];
        }

        return $this->seats()->tap($this->freeSeatsConstraint())->count();

    }

    public function numberOfBookings(): int
    {
        return array_key_exists('bookings_count', $this->attributes)
            ? (int) $this->attributes['bookings_count']
            : $this->bookings()->count();
    }

    public function numberOfBookedSeats(): int
    {
        if (array_key_exists('booked_seats_count', $this->attributes)) {
            return (int) $this->attributes['booked_seats_count'];
        }

        return $this->bookings()->whereNotNull('seat_id')->count();

    }

    public function isFull(): bool
    {
        // Callers that eager-load a `has_available_seat` existence flag (withExists — e.g. the
        // public website's CaravaneDepartsResource) skip the per-bus seat query entirely.
        if (array_key_exists('has_available_seat', $this->attributes)) {
            return ! $this->attributes['has_available_seat'];
        }

        return $this->seatsLeft() <= 0;

    }

    public function isClosed(): bool
    {
        return (bool) $this->closed || (bool) $this->depart->closed;

    }

    public function hasEnoughSeatsForBookings(int $numberOfBookings): bool
    {
        return $this->seatsLeft() >= $numberOfBookings;

    }

    public function getAvailableSeats(): Collection
    {
        return $this->seats()
            ->whereNotExists(function ($query) {
                $query->select('id')
                    ->from('bookings')
                    ->whereColumn('bookings.seat_id', 'bus_seats.id')
                    ->whereNull('bookings.deleted_at');
            })
            ->lockForUpdate()
            ->orderBy('bus_seats.seat_id', 'asc')->get();

    }

    public function getOneAvailableSeat(): BusSeat
    {
        $availableSeat = $this->getAvailableSeats()->first();
        if ($availableSeat == null) {
            throw new ModelNotFoundException('Aucun siège trouvé dans ce bus '.$this->full_name);
        }

        return $availableSeat;

    }

    public function numberOfTicketsSold(): int
    {
        if (array_key_exists('tickets_sold_count', $this->attributes)) {
            return (int) $this->attributes['tickets_sold_count'];
        }

        // bookings that have tickets
        return $this->bookings()->whereNotNull('ticket_id')->count();
    }

    public function getFullNameAttribute(): string
    {

        return $this->depart->identifier(with_trajet_prefix: true).' - '.$this->name;

    }

    /**
     * Parses agent_numbers (numbers separated by "/", e.g. "77xxxxxxx/78xxxxxxx") into individual
     * phone numbers. Empty when no field agent contact is set for this bus.
     *
     * @return string[]
     */
    public function getAgentNumbersList(): array
    {
        if (empty($this->agent_numbers)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode('/', $this->agent_numbers))));
    }

    /**
     * The number(s) customers should call for info about this bus: this bus's own field agent
     * (agent_numbers) when set, otherwise the given fallback (typically an app-wide default).
     */
    public function resolveAgentContactNumber(string|int|null $fallback = null): string
    {
        return $this->agent_numbers ?: (string) $fallback;
    }

    // add global scope filter buses for departs that are not cancelled
    protected static function boot(): void
    {
        parent::boot();
        static::addGlobalScope('notCancelled', function ($query) {
            $query->whereHas('depart', function ($query) {
                $query->where('canceled', false);
            });
        });
    }

    public function close(): self
    {
        $this->closed = true;
        $this->closed_at = now();

        return $this;
    }

    public function open(): self
    {
        $this->closed = false;
        $this->closed_at = null;

        return $this;
    }

    public function waitingCustomers(): HasMany
    {
        return $this->hasMany(WaitingCustomer::class);

    }

    public function pointDeparts(): HasMany
    {
        return $this->hasMany(PointDepBus::class);

    }

    public function destinations(): HasMany
    {
        return $this->hasMany(DestinationBus::class);

    }

    public function getClimatiseAttribute(): bool
    {
        return $this->vehicule?->vehicule_type == Vehicule::VEHICULE_TYPE_CLIMATISE;
    }

    public function getTicketPriceAttribute(): int
    {
        if (is_request_for_gp_customers()) {
            if ($this->attributes['gp_ticket_price'] == null) {
                // TODO make this dynamic later
                return 6600;
            } else {
                return max($this->attributes['gp_ticket_price'], $this->attributes['ticket_price']);
            }
        } else {
            return $this->attributes['ticket_price'];
        }
    }

    public function itinerary(): BelongsTo
    {
        return $this->belongsTo(Itinerary::class);
    }

    protected $casts = [
        'closed' => 'boolean',
        'closed_at' => 'datetime',

    ];
}
