<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TicketPayment extends Model
{
    //
    const STATUS_PENDING = "PENDING";
    const STATUS_SUCCESS = "SUCCESS";
    const STATUS_FAILED = "FAILED";
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
