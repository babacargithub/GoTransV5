<?php

namespace App\Models;

use App\Enums\ExpenseTypeCode;
use Database\Factories\ExpenseTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A category of company spending ("Location de bus", "Crédit et passe internet", ...)
 * attached to every EXPENSE AccountTransaction so spending can be totalled by type.
 */
class ExpenseType extends Model
{
    /** @use HasFactory<ExpenseTypeFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'is_system',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function accountTransactions(): HasMany
    {
        return $this->hasMany(AccountTransaction::class);
    }

    /**
     * Look up a built-in system expense type by its stable code.
     */
    public static function findByCode(ExpenseTypeCode $code): ?self
    {
        return self::where('code', $code->value)->first();
    }

    /**
     * Id of a built-in system type, for callers that classify an expense automatically.
     */
    public static function idForCode(ExpenseTypeCode $code): ?int
    {
        return self::findByCode($code)?->id;
    }
}
