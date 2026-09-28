<?php

namespace App\Models;

use App\Enums\AccountTransactionType;
use App\Enums\AccountType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    protected $fillable = [
        'name',
        'account_type',
        'balance',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'account_type' => AccountType::class,
            'balance' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(AccountTransaction::class);
    }

    /**
     * Recompute the account balance from its full transaction ledger.
     *
     * This is the authoritative formula and must always agree with the cached
     * `balance` column. Call this for integrity checks only — the cached
     * column is used for all normal reads.
     */
    public function computeBalanceFromLedger(): int
    {
        $credit = $this->transactions()
            ->where('transaction_type', AccountTransactionType::Credit->value)
            ->sum('amount');

        $debit = $this->transactions()
            ->where('transaction_type', AccountTransactionType::Debit->value)
            ->sum('amount');

        return $credit - $debit;
    }

    public function updateBalanceFromLedger(): self
    {
        $this->balance = $this->computeBalanceFromLedger();
        $this->save();

        return $this;
    }
}
