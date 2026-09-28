<?php

namespace App\Models;

use App\Enums\CaisseCode;
use App\Enums\CaisseTransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Caisse extends Model
{
    protected $fillable = [
        'name',
        'code',
        'balance',
        'closed',
        'locked_until',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'integer',
            'closed' => 'boolean',
            'locked_until' => 'date',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(CaisseTransaction::class);
    }

    /**
     * Look up a built-in system caisse by its stable code (see CaisseCode).
     */
    public static function findByCode(CaisseCode $code): ?self
    {
        return self::where('code', $code->value)->first();
    }

    /**
     * A caisse seeded with a stable code (Wave, OM, Ticket Cash, Caisse
     * Principale) that payment-method routing depends on — it cannot be
     * deleted, unlike an ad-hoc caisse created later through the UI.
     */
    public function isSystemCaisse(): bool
    {
        return $this->code !== null;
    }

    /**
     * Returns true when the caisse has been locked for today via "Clôturer la journée".
     * A locked caisse rejects new manual entrée/sortie/transfer operations until tomorrow.
     */
    public function isLockedForToday(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isToday();
    }

    /**
     * Recompute the caisse balance from its full transaction ledger and persist it.
     *
     * Formula: SUM(amount WHERE type=DEPOSIT) − SUM(amount WHERE type=WITHDRAW)
     *
     * Call this after any CaisseTransaction is created or deleted so the cached
     * balance column stays authoritative.
     */
    public function updateBalanceFromLedger(): self
    {
        $deposits = $this->transactions()
            ->where('transaction_type', CaisseTransactionType::Deposit->value)
            ->sum('amount');

        $withdrawals = $this->transactions()
            ->where('transaction_type', CaisseTransactionType::Withdraw->value)
            ->sum('amount');

        $this->balance = $deposits - $withdrawals;
        $this->save();

        return $this;
    }
}
