<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Trajet;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only counts about customers.
 */
class CustomerService
{
    /**
     * Customers created in the period; with a trajet, only those who have a booking on one of its départs.
     */
    public function countNewCustomers(CarbonInterface $periodStart, CarbonInterface $periodEnd, ?Trajet $trajet = null): int
    {
        return $this->newCustomersQuery($periodStart, $periodEnd, $trajet)->count();
    }

    /**
     * Same scope as countNewCustomers(), restricted to the customers attributed to an ambassador.
     */
    public function countNewCustomersBroughtByAmbassadors(CarbonInterface $periodStart, CarbonInterface $periodEnd, ?Trajet $trajet = null): int
    {
        return $this->newCustomersQuery($periodStart, $periodEnd, $trajet)->whereNotNull('ambassador_id')->count();
    }

    /**
     * @return Builder<Customer>
     */
    private function newCustomersQuery(CarbonInterface $periodStart, CarbonInterface $periodEnd, ?Trajet $trajet): Builder
    {
        return Customer::query()
            ->whereBetween('created_at', [$periodStart, $periodEnd])
            ->when($trajet, fn (Builder $customers) => $customers->whereHas(
                'bookings.depart',
                fn (Builder $departs) => $departs->where('trajet_id', $trajet->id),
            ));
    }
}
