<?php

namespace App\Models;

use Database\Factories\AmicaleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A student association ("amicale") that uses the public website on its own subdomain and earns a fixed
 * commission on every ticket its members book there.
 */
class Amicale extends Model
{
    /** @use HasFactory<AmicaleFactory> */
    use HasFactory;

    /** Role carried by the user account an amicale logs in with. */
    public const ACCOUNT_ROLE_NAME = 'ROLE_AMICALE';

    /** Subdomains already used by the platform itself: no amicale may take them. */
    public const RESERVED_SLUGS = ['www', 'admin', 'amicales', 'ambassadeurs', 'api', 'mail', 'static'];

    protected $fillable = [
        'name',
        'slug',
        'welcome_message',
        'commission_per_ticket',
        'is_active',
        'user_account_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'commission_per_ticket' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function userAccount(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_account_id');
    }

    /**
     * The trajets this amicale's site offers. An amicale without any is not restricted: its site offers them all.
     */
    public function trajets(): BelongsToMany
    {
        return $this->belongsToMany(Trajet::class, 'amicale_trajet');
    }

    public function servesTrajet(Trajet $trajet): bool
    {
        return ! $this->trajets()->exists() || $this->trajets()->whereKey($trajet->getKey())->exists();
    }

    /**
     * Narrows a trajets query to the ones this amicale's site offers.
     *
     * @param  Builder<Trajet>  $trajetsQuery
     * @return Builder<Trajet>
     */
    public function limitToServedTrajets(Builder $trajetsQuery): Builder
    {
        $servedTrajetIds = $this->trajets()->pluck('trajets.id');

        return $servedTrajetIds->isEmpty() ? $trajetsQuery : $trajetsQuery->whereIn('trajets.id', $servedTrajetIds);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * The message shown on the amicale's site: the one they chose, or the standard partnership sentence.
     */
    public function getDisplayedWelcomeMessageAttribute(): string
    {
        return $this->welcome_message ?: $this->name.' en partenariat avec Globe One Transports';
    }

    /**
     * Why this amicale's account may not use its space right now, or null when it may.
     */
    public function accessRejectionMessage(): ?string
    {
        return $this->is_active ? null : "L'accès de votre amicale est désactivé. Contactez Globe One Transports.";
    }
}
