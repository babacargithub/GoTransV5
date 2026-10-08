<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketPayment extends Model
{
    //
    const STATUS_PENDING = 'PENDING';

    const STATUS_SUCCESS = 'SUCCESS';

    const STATUS_FAILED = 'FAILED';

    protected $fillable = [
        'meta_data',
        'payement_method',
        'refunded',
        'refunded_at',
        'phone_number',
        'status',
        'is_for_multiple_booking',
        'group_id',
        'montant',
        'ticket_id',
        'provider_transaction_id',
        'proofs',
        'proof_note',
        'recorded_by_user_id',
    ];

    /**
     * `proofs` holds the private-disk paths of the screenshots attached when an agent
     * settled the payment manually (one or many).
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'proofs' => 'array',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
