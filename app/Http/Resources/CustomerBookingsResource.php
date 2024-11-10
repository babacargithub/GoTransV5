<?php

namespace App\Http\Resources;

use App\Models\Customer;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerBookingsResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            /** @var $customer Customer */
            $customer = $this->resource,
            'id' => $customer->id,
            'name' => $customer->nom,
            "full_name" => $customer->full_name,
            'phone' => $customer->phone_number,
            'email' => $customer->email,
            'created_at' => $customer->created_at->format('d-m-Y H:i:s'),
        ];
    }

}