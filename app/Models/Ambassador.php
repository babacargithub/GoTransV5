<?php

namespace App\Models;

use App\Enums\AmbassadorStatus;
use Database\Factories\AmbassadorFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function earnings(): HasMany
    {
        return $this->hasMany(AmbassadorEarning::class);
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
     * Whether this ambassador's code may currently attribute new customers.
     */
    public function canAcquireCustomers(): bool
    {
        return $this->status === AmbassadorStatus::Active && $this->hasRunningContractOn();
    }

    /**
     * Active ambassadors whose contract covers today: the only ones whose code attributes new customers.
     */
    public function scopeAcquiringCustomers(Builder $query): Builder
    {
        return $query
            ->where('status', AmbassadorStatus::Active)
            ->whereDate('contract_ends_at', '>=', today())
            ->where(fn (Builder $contractStartQuery) => $contractStartQuery
                ->whereNull('contract_starts_at')
                ->orWhereDate('contract_starts_at', '<=', today()));
    }

    public function scopeWithCode(Builder $query, string $enteredCode): Builder
    {
        return $query->where('code', strtoupper(trim($enteredCode)));
    }
}
