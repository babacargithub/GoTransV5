<?php

namespace App\Models;

use App\Enums\CaisseTransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaisseTransaction extends Model
{
    protected $fillable = [
        'caisse_id',
        'user_id',
        'amount',
        'label',
        'transaction_type',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'transaction_type' => CaisseTransactionType::class,
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (CaisseTransaction $transaction): void {
            if ($transaction->transaction_type === null) {
                if ($transaction->amount < 0) {
                    $transaction->amount = abs($transaction->amount);
                    $transaction->transaction_type = CaisseTransactionType::Withdraw;
                } else {
                    $transaction->transaction_type = CaisseTransactionType::Deposit;
                }
            }
        });
    }

    public function caisse(): BelongsTo
    {
        return $this->belongsTo(Caisse::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getEffectiveAmountAttribute(): int
    {
        return $this->transaction_type === CaisseTransactionType::Withdraw
            ? -$this->amount
            : $this->amount;
    }
}
