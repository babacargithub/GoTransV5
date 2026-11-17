<?php

namespace Database\Seeders;

use App\Enums\AmbassadorStatus;
use App\Manager\BookingManager;
use App\Models\Ambassador;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Depart;
use App\Models\Destination;
use App\Models\PointDep;
use App\Models\Ticket;
use App\Services\AmbassadorEarningService;
use App\Services\AmbassadorService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Local demo data for the ambassador back-office: ten ambassadors (the two that already exist plus eight new
 * ones, some sponsored, one suspended, one awaiting approval), each with acquired customers who booked and paid
 * a ticket on an existing departure. Bookings on past departures give approved commissions, bookings on upcoming
 * departures give pending ones. Never runs in production, and an ambassador who already has customers is skipped.
 *
 * Run with: php artisan db:seed --class=AmbassadorDemoSeeder
 */
class AmbassadorDemoSeeder extends Seeder
{
    private const DEPARTS_PER_GROUP_COUNT = 5;

    private const MINIMUM_DAYS_SINCE_PAST_DEPART = 3;

    private const FIRST_NAMES = ['Awa', 'Moussa', 'Fatou', 'Ibrahima', 'Khadija', 'Cheikh', 'Mariama', 'Ousmane', 'Aïssatou', 'Mamadou', 'Ndeye', 'Abdoulaye', 'Rokhaya', 'Papa', 'Seynabou', 'Modou', 'Coumba', 'Lamine', 'Bineta', 'Serigne'];

    private const LAST_NAMES = ['Ndiaye', 'Diop', 'Fall', 'Sow', 'Ba', 'Diallo', 'Sy', 'Sarr', 'Faye', 'Thiam', 'Diouf', 'Gueye', 'Seck', 'Mbaye', 'Cissé', 'Kane', 'Niang', 'Camara', 'Sène', 'Badji'];

    /**
     * Ambassadors to create, with how many customers each brings and who sponsors them (by position in
     * this list, 0 and 1 being the two existing ambassadors).
     *
     * @var list<array{first_name: string, last_name: string, phone_number: string, status: AmbassadorStatus, sponsor_index: ?int, customers_count: int}>
     */
    private const NEW_AMBASSADORS = [
        ['first_name' => 'Moussa', 'last_name' => 'Ndiaye', 'phone_number' => '776104418', 'status' => AmbassadorStatus::Active, 'sponsor_index' => 0, 'customers_count' => 15],
        ['first_name' => 'Fatou', 'last_name' => 'Sow', 'phone_number' => '781120937', 'status' => AmbassadorStatus::Active, 'sponsor_index' => 0, 'customers_count' => 6],
        ['first_name' => 'Ibrahima', 'last_name' => 'Fall', 'phone_number' => '775531206', 'status' => AmbassadorStatus::Active, 'sponsor_index' => null, 'customers_count' => 5],
        ['first_name' => 'Khadija', 'last_name' => 'Ba', 'phone_number' => '784407725', 'status' => AmbassadorStatus::Active, 'sponsor_index' => null, 'customers_count' => 9],
        ['first_name' => 'Cheikh', 'last_name' => 'Diallo', 'phone_number' => '770283391', 'status' => AmbassadorStatus::Suspended, 'sponsor_index' => null, 'customers_count' => 4],
        ['first_name' => 'Mariama', 'last_name' => 'Sy', 'phone_number' => '782965540', 'status' => AmbassadorStatus::Active, 'sponsor_index' => 1, 'customers_count' => 7],
        ['first_name' => 'Ousmane', 'last_name' => 'Sarr', 'phone_number' => '779018862', 'status' => AmbassadorStatus::Active, 'sponsor_index' => null, 'customers_count' => 3],
        ['first_name' => 'Aïssatou', 'last_name' => 'Faye', 'phone_number' => '783376019', 'status' => AmbassadorStatus::PendingApproval, 'sponsor_index' => 1, 'customers_count' => 0],
    ];

    /** Customers brought by the two ambassadors that already exist, in the order of the first two ids. */
    private const EXISTING_AMBASSADORS_CUSTOMERS_COUNTS = [12, 8];

    public function run(AmbassadorService $ambassadorService, AmbassadorEarningService $earningService): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('AmbassadorDemoSeeder is for local demo data and never runs in production.');
        }

        $pastDeparts = $this->findBookableDeparts(upcoming: false);
        $upcomingDeparts = $this->findBookableDeparts(upcoming: true);

        if ($pastDeparts->isEmpty() || $upcomingDeparts->isEmpty()) {
            throw new RuntimeException('Needs past and upcoming departures with a bus, a pick-up point and a destination.');
        }

        DB::transaction(function () use ($ambassadorService, $earningService, $pastDeparts, $upcomingDeparts): void {
            $ambassadors = $this->createAmbassadors($ambassadorService);
            $customersCounts = array_merge(
                self::EXISTING_AMBASSADORS_CUSTOMERS_COUNTS,
                array_column(self::NEW_AMBASSADORS, 'customers_count'),
            );

            foreach ($ambassadors as $position => $ambassador) {
                if ($ambassador->customers()->exists()) {
                    continue;
                }

                for ($customerNumber = 0; $customerNumber < $customersCounts[$position]; $customerNumber++) {
                    $bookOnPastDepart = $customerNumber % 5 < 3;
                    $depart = ($bookOnPastDepart ? $pastDeparts : $upcomingDeparts)->random();

                    $booking = $this->createPaidBookingForNewCustomer($ambassador, $depart);
                    $earningService->recordAcquisitionEarning($booking);
                }
            }

            $earningService->approveDueEarnings();
        });
    }

    /**
     * The two existing ambassadors first, then the new ones (created once, matched by phone number).
     *
     * @return list<Ambassador>
     */
    private function createAmbassadors(AmbassadorService $ambassadorService): array
    {
        $ambassadors = Ambassador::query()->orderBy('id')->limit(2)->get()->all();

        if (count($ambassadors) < 2) {
            throw new RuntimeException('The two existing ambassadors are expected to be in the database.');
        }

        foreach (self::NEW_AMBASSADORS as $ambassadorDefinition) {
            $isActiveOrSuspended = in_array($ambassadorDefinition['status'], [AmbassadorStatus::Active, AmbassadorStatus::Suspended], true);

            $ambassadors[] = Ambassador::query()->firstOrCreate(
                ['phone_number' => (int) $ambassadorDefinition['phone_number']],
                [
                    'first_name' => $ambassadorDefinition['first_name'],
                    'last_name' => $ambassadorDefinition['last_name'],
                    'wave_phone_number' => (int) $ambassadorDefinition['phone_number'],
                    'code' => $ambassadorService->generateUniqueCode(),
                    'status' => $ambassadorDefinition['status'],
                    'sponsor_ambassador_id' => $ambassadorDefinition['sponsor_index'] !== null ? $ambassadors[$ambassadorDefinition['sponsor_index']]->id : null,
                    'contract_starts_at' => $isActiveOrSuspended ? today()->subMonths(2) : null,
                    'contract_ends_at' => $isActiveOrSuspended ? today()->addMonths(10) : null,
                    'approved_at' => $isActiveOrSuspended ? now()->subMonths(2) : null,
                ],
            );
        }

        return $ambassadors;
    }

    /**
     * @return Collection<int, Depart>
     */
    private function findBookableDeparts(bool $upcoming): Collection
    {
        $departsQuery = Depart::query()
            ->where('canceled', false)
            ->whereHas('buses')
            ->whereHas('trajet.pointDeps')
            ->whereHas('trajet.destinations');

        return $upcoming
            ? $departsQuery->where('date', '>=', now())->orderBy('date')->limit(self::DEPARTS_PER_GROUP_COUNT)->get()
            : $departsQuery->where('date', '<', now()->subDays(self::MINIMUM_DAYS_SINCE_PAST_DEPART))->orderByDesc('date')->limit(self::DEPARTS_PER_GROUP_COUNT)->get();
    }

    private function createPaidBookingForNewCustomer(Ambassador $ambassador, Depart $depart): Booking
    {
        $customer = Customer::create([
            'prenom' => fake()->randomElement(self::FIRST_NAMES),
            'nom' => fake()->randomElement(self::LAST_NAMES),
            'phone_number' => $this->generateUnusedPhoneNumber(),
            'ambassador_id' => $ambassador->id,
            'last_active' => now(),
        ]);

        $bus = $depart->buses()->firstOrFail();

        $ticket = new Ticket;
        $ticket->forceFill([
            'number' => random_int(1_000_000, 9_999_999_999),
            'price' => $bus->ticket_price,
            'payment_method' => 'wave',
            'comment' => 'demo-'.Str::random(10),
            'used' => false,
            'soldBy' => 'system',
            'soldAt' => now(),
            'expiryDate' => now()->addDays(30),
        ])->save();

        return Booking::create([
            'customer_id' => $customer->id,
            'depart_id' => $depart->id,
            'bus_id' => $bus->id,
            'point_dep_id' => PointDep::query()->where('trajet_id', $depart->trajet_id)->inRandomOrder()->firstOrFail()->id,
            'destination_id' => Destination::query()->where('trajet_id', $depart->trajet_id)->inRandomOrder()->firstOrFail()->id,
            'ticket_id' => $ticket->id,
            'paye' => true,
            'ambassador_id' => $ambassador->id,
            'uuid' => (string) Str::uuid(),
            'group_id' => (int) BookingManager::generateBookingGroupId(),
        ]);
    }

    private function generateUnusedPhoneNumber(): string
    {
        do {
            $phoneNumber = fake()->randomElement(['77', '78', '76', '70']).fake()->numerify('#######');
        } while (Customer::query()->where('phone_number', $phoneNumber)->exists());

        return $phoneNumber;
    }
}
