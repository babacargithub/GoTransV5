<?php

namespace App\Models;

use Database\Factories\MonthlyExpenseBudgetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The planned (fixed) cost of one expense type for one calendar month, e.g. "Salaire employé,
 * décembre 2026 = 200 000 FCFA". Months are planned independently of each other; a type without
 * a row for a month has no fixed cost that month. `month` is always the first day of the month.
 */
class MonthlyExpenseBudget extends Model
{
    /** @use HasFactory<MonthlyExpenseBudgetFactory> */
    use HasFactory;

    protected $fillable = [
        'expense_type_id',
        'month',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'month' => 'date',
            'amount' => 'integer',
        ];
    }

    public function expenseType(): BelongsTo
    {
        return $this->belongsTo(ExpenseType::class);
    }
}
