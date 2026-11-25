<?php

namespace App\Services;

use App\Models\Depart;
use App\Models\Trajet;
use Carbon\CarbonInterface;

/**
 * Read-only counts about départs.
 */
class DepartService
{
    /**
     * Non-cancelled départs whose date falls in the period, optionally on a single trajet.
     */
    public function countDeparts(CarbonInterface $periodStart, CarbonInterface $periodEnd, ?Trajet $trajet = null): int
    {
        return Depart::query()
            ->whereBetween('date', [$periodStart, $periodEnd])
            ->when($trajet, fn ($departs) => $departs->where('trajet_id', $trajet->id))
            ->count();
    }
}
