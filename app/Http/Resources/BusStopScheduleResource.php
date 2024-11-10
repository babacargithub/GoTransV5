<?php

namespace App\Http\Resources;

use App\Models\HeureDepart;
use App\Models\PointDep;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BusStopScheduleResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /**
         * {
         * "@id": "/api/bus_stop_schedules/29180",
         * "@type": "BusStopSchedule",
         * "id": 29180,
         * "rendezVousSchedule": "1970-01-01T04:00:00+00:00",
         * "rendezVousPoint": "devant le village L",
         * "depart": "/api/departs/3028",
         * "pointDep": "/api/point_departs/27",
         * "bus": null
         * }
         */
        return [
            /** @var $this HeureDepart */
            'id' => $this->id,
            'rendezVousSchedule' => $this->rendez_vous_schedule,
            'rendezVousPoint' => $this->rendez_vous_point,
            'depart' => '/api/departs/'.$this->depart_id,
            'pointDep' => '/api/point_departs/'.$this->point_dep_id,
            'bus' => null
        ];
    }
}
