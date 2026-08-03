<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmsDeliveryReceipt extends Model
{
    const STATUS_DELIVERED_TO_NETWORK = 'DeliveredToNetwork';
    const STATUS_DELIVERY_UNCERTAIN = 'DeliveryUncertain';
    const STATUS_DELIVERY_IMPOSSIBLE = 'DeliveryImpossible';
    const STATUS_MESSAGE_WAITING = 'MessageWaiting';
    const STATUS_DELIVERED_TO_TERMINAL = 'DeliveredToTerminal';

    protected $fillable = [
        'callback_data',
        'phone_number',
        'delivery_status',
        'raw_payload',
    ];

    protected $casts = [
        'raw_payload' => 'array',
    ];
}
