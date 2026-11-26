<?php

namespace App\Models;

use App\Enums\AmbassadorAbilityName;
use App\Enums\AmbassadorStatus;
use Database\Factories\AmbassadorFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Ambassador extends Model
{
    /** @use HasFactory<AmbassadorFactory> */
    use HasFactory;

    /** Role carried by the user account an ambassador logs in with. */
    public const ACCOUNT_ROLE_NAME = 'ROLE_AMBASSADOR';

    protected $fillable = [
        'sponsor_ambassador_id',
        'user_account_id',
        'first_name',
        'last_name',
        'phone_number',
        'wave_phone_number',
        'code',
        'invitation_token',
        'status',
        'contract_starts_at',
        'contract_ends_at',
        'approved_by_user_id',
        'approved_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AmbassadorStatus::class,
            'contract_starts_at' => 'date',
            'contract_ends_at' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * A new ambassador starts with every ability; a manager revokes the ones they should not have.
     */
    protected static function booted(): void
    {
        static::created(function (self $createdAmbassador): void {
            $createdAmbassador->abilities()->syncWithoutDetaching(AmbassadorAbility::query()->pluck('id'));
        });
    }

    /**
     * Codes are compared case-insensitively by customers, so they are always stored upper-cased.
     */
    protected function code(): Attribute
    {
        return Attribute::make(
            set: fn (string $enteredCode): string => strtoupper(trim($enteredCode)),
        );
    }

    public function getFullNameAttribute(): string
    {
        return $this->first_name.' '.$this->last_name;
    }

    /**
     * Wave number payouts go to: the dedicated one when set, otherwise the ambassador's own number.
     */
    public function getPayoutPhoneNumberAttribute(): int
    {
        return (int) ($this->wave_phone_number ?? $this->phone_number);
    }

    public function abilities(): BelongsToMany
    {
        return $this->belongsToMany(AmbassadorAbility::class, 'ambassador_ability_ambassador')->withTimestamps();
    }

    public function hasAbility(AmbassadorAbilityName $abilityName): bool
    {
        return $this->abilities()->where('code', $abilityName->value)->exists();
    }

    public function userAccount(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_account_id');
    }

    public function sponsor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'sponsor_ambassador_id');
    }

    public function sponsoredAmbassadors(): HasMany
    {
        return $this->hasMany(self::class, 'sponsor_ambassador_id');
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    /**
     * Customers brought by this ambassador who still hold at least one booking: those who never booked,
     * or whose bookings were all cancelled (soft-deleted), are not counted as acquired.
     */
    public function customersWithActiveBooking(): HasMany
    {
        return $this->customers()->whereHas('bookings');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function earnings(): HasMany
    {
        return $this->hasMany(AmbassadorEarning::class);
    }

    /**
     * Earnings (such as a sponsor's bonus) that exist because of this ambassador's own activity.
     */
    public function earningsGeneratedForSponsor(): HasMany
    {
        return $this->hasMany(AmbassadorEarning::class, 'source_ambassador_id');
    }

    public function contractSignatures(): HasMany
    {
        return $this->hasMany(AmbassadorContractSignature::class);
    }

    /**
     * Whether this ambassador signed a contract. The version they signed stays theirs: a later version of the
     * contract only concerns ambassadors who have not signed yet.
     */
    public function hasSignedContract(): bool
    {
        return $this->contractSignatures()->exists();
    }

    /**
     * The signature of the contract version this ambassador agreed to, if any.
     */
    public function signedContract(): ?AmbassadorContractSignature
    {
        return $this->contractSignatures()->latest('signed_at')->first();
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(AmbassadorPayout::class);
    }

    /**
     * Whether the contract covers the given moment (today by default). A missing end date means the
     * contract has not been set yet, which never counts as running.
     */
    public function hasRunningContractOn(?DateTimeInterface $moment = null): bool
    {
        $moment = $moment ? Carbon::instance($moment)->startOfDay() : today();

        if ($this->contract_ends_at === null || $this->contract_ends_at->lt($moment)) {
            return false;
        }

        return $this->contract_starts_at === null || $this->contract_starts_at->lte($moment);
    }

    /**
     * Whether the contract has an end date that is already behind us. A contract that is not set yet or has
     * not started is not expired.
     */
    public function hasExpiredContract(): bool
    {
        return $this->contract_ends_at !== null && $this->contract_ends_at->lt(today());
    }

    /**
     * The French reason why this ambassador may not use their space, or null when they may: a suspended
     * account or an expired contract. Shown at login and when an open session is cut off.
     */
    public function accessRejectionMessage(): ?string
    {
        if ($this->status === AmbassadorStatus::Suspended) {
            return __('auth.ambassador_suspended');
        }

        return $this->hasExpiredContract() ? __('auth.ambassador_contract_expired') : null;
    }

    /**
     * Whether this ambassador's code may currently attribute new customers.
     */
    public function canAcquireCustomers(): bool
    {
        return $this->status === AmbassadorStatus::Active
            && $this->hasRunningContractOn()
            && $this->hasSignedContract();
    }

    /**
     * Active ambassadors whose contract covers today and who signed their contract: the only ones whose code
     * attributes new customers.
     */
    public function scopeAcquiringCustomers(Builder $query): Builder
    {
        return $query
            ->where('status', AmbassadorStatus::Active)
            ->withSignedContract()
            ->whereDate('contract_ends_at', '>=', today())
            ->where(fn (Builder $contractStartQuery) => $contractStartQuery
                ->whereNull('contract_starts_at')
                ->orWhereDate('contract_starts_at', '<=', today()));
    }

    /**
     * Ambassadors who signed the contract, whichever version they signed.
     */
    public function scopeWithSignedContract(Builder $query): Builder
    {
        return $query->whereHas('contractSignatures');
    }

    public function scopeWithCode(Builder $query, string $enteredCode): Builder
    {
        return $query->where('code', strtoupper(trim($enteredCode)));
    }
}
