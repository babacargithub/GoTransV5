<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppParams extends Model
{
    //
    protected $fillable = [
        'data'
    ];
    protected $table = 'app_params';
    protected $casts = [
        'data' => 'array'
    ];

    public function getBusAgentDefaultNumber()
    {

        return $this->data['bus_agent_default_number']?? 771273535;
    }
}
