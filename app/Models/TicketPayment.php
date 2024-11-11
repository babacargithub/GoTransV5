<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TicketPayment extends Model
{
    //
    const STATUS_PENDING = "STATUS_PENDING";
    const STATUS_SUCCESS = "STATUS_SUCCESS";
    const STATUS_FAILED = "STATUS_FAILED";
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
