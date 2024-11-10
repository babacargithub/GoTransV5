<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TicketPayment extends Model
{
    //
    protected $fillable=[
        "meta_data",
        "payement_method",
        "refunded",
        "refunded_at",
        "phone_number",
        "status",
        "is_for_multiple_booking",
        "group_id",
        "montant",
    ];


}
