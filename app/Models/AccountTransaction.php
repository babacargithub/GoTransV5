<?php

namespace App\Models;

use App\Enums\AccountTransactionCategory;
use App\Enums\AccountTransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountTransaction extends Model
{
    protected $fillable = [
        'account_id',
        'user_id',
        'amount',
        'transaction_type',
        'category',
        'label',
        'reference_type',
        'reference_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'transaction_type' => AccountTransactionType::class,
            'category' => AccountTransactionCategory::class,
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Returns the signed effective amount: positive for credits (money in),
     * negative for debits (money out).
     */
    public function getEffectiveAmountAttribute(): int
    {
        return $this->transaction_type === AccountTransactionType::Credit
            ? $this->amount
            : -$this->amount;
    }
}
