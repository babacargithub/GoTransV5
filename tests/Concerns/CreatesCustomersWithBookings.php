<?php

namespace Tests\Concerns;

use App\Models\Ambassador;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Depart;
use App\Models\Destination;
use App\Models\PointDep;
use App\Models\Trajet;

trait CreatesCustomersWithBookings
{
    private function createCustomerBroughtBy(?Ambassador $ambassador): Customer
    {
        return Customer::create([
            'prenom' => 'Test',
            'nom' => 'Client',
            'phone_number' => fake()->unique()->numerify('77#######'),
            'ambassador_id' => $ambassador?->id,
        ]);
    }

    /**
     * A booking of the customer on a fresh départ; a cancelled booking is a soft-deleted one.
     */
    private function createBookingOfCustomer(Customer $customer, bool $isCancelled = false): Booking
    {
        $trajet = Trajet::create(['name' => 'Trajet '.uniqid(), 'departure_city' => 'DAKAR', 'arrival_city' => 'SAINT-LOUIS']);
        $depart = Depart::create([
            'name' => 'DEPART '.uniqid(),
            'date' => now()->addDays(5),
            'trajet_id' => $trajet->id,
            'closed' => false,
            'locked' => false,
            'canceled' => false,
        ]);
        $bus = $depart->buses()->create([
            'name' => 'Bus Test',
            'nombre_place' => 50,
            'ticket_price' => 4000,
            'gp_ticket_price' => 6000,
            'closed' => false,
        ]);

        $booking = Booking::create([
            'customer_id' => $customer->id,
            'depart_id' => $depart->id,
            'bus_id' => $bus->id,
            'point_dep_id' => PointDep::create(['name' => 'Gare '.uniqid(), 'trajet_id' => $trajet->id])->id,
            'destination_id' => Destination::create(['name' => 'Centre', 'trajet_id' => $trajet->id])->id,
            'paye' => true,
        ]);

        if ($isCancelled) {
            $booking->delete();
        }

        return $booking;
    }
}
