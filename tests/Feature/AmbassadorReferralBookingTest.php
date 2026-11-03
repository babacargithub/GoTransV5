<?php

namespace Tests\Feature;

use App\Livewire\Website\StudentBooking;
use App\Models\Ambassador;
use App\Models\AmbassadorEarning;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Depart;
use App\Models\Destination;
use App\Models\PointDep;
use App\Models\Trajet;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AmbassadorReferralBookingTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return array{depart: Depart, pointDep: PointDep}
     */
    private function createBookableDepart(): array
    {
        $trajet = Trajet::create([
            'name' => 'Caravane '.uniqid(),
            'departure_city' => 'DAKAR',
            'arrival_city' => 'SAINT-LOUIS',
        ]);
        $depart = Depart::create([
            'name' => 'DEPART REF '.uniqid(),
            'date' => now()->addDays(5),
            'trajet_id' => $trajet->id,
            'visibilite' => Depart::VISIBILITE_ALL_CUSTOMERS,
            'closed' => false,
            'locked' => false,
            'canceled' => false,
        ]);
        $bus = $depart->buses()->create([
            'name' => 'Bus Referral Test',
            'nombre_place' => 50,
            'ticket_price' => 4000,
            'gp_ticket_price' => 6000,
            'closed' => false,
        ]);
        $bus->seats()->createMany(
            collect(range(1, 10))->map(fn (int $position) => [
                'booked' => false,
                'price' => 4000,
                'position_in_bus' => $position,
            ])->all()
        );
        $pointDep = PointDep::create([
            'name' => 'Gare routière '.uniqid(),
            'trajet_id' => $trajet->id,
            'disabled' => false,
            'visibilite' => Depart::VISIBILITE_ALL_CUSTOMERS,
        ]);
        Destination::create(['name' => 'Dakar centre', 'trajet_id' => $trajet->id]);

        return ['depart' => $depart, 'pointDep' => $pointDep];
    }

    private function fakeWaveCheckout(): void
    {
        Http::fake([
            'api.wave.com/*' => Http::response([
                'id' => 'cos-test123',
                'wave_launch_url' => 'https://pay.wave.com/c/cos-test123?a=8000',
            ], 200),
        ]);
    }

    /**
     * A valid Senegalese mobile number that no customer of the test database uses yet.
     */
    private function unusedPassengerPhoneNumber(): string
    {
        do {
            $phoneNumber = '77'.random_int(1000000, 9999999);
        } while (Customer::where('phone_number', $phoneNumber)->exists() || Ambassador::where('phone_number', $phoneNumber)->exists());

        return $phoneNumber;
    }

    /**
     * @param  array<int, string>  $passengerPhoneNumbers
     */
    private function bookWithPhoneNumbers(Depart $depart, PointDep $pointDep, array $passengerPhoneNumbers, ?string $ref): Testable
    {
        $this->fakeWaveCheckout();
        $bookingComponent = Livewire::withQueryParams($ref === null ? [] : ['ref' => $ref])
            ->test(StudentBooking::class, ['depart' => $depart])
            ->set('passengersCount', count($passengerPhoneNumbers));

        foreach ($passengerPhoneNumbers as $passengerIndex => $passengerPhoneNumber) {
            $bookingComponent
                ->set("passengers.{$passengerIndex}.full_name", 'Awa Diop'.chr(65 + $passengerIndex))
                ->set("passengers.{$passengerIndex}.phone_number", $passengerPhoneNumber)
                ->set("passengers.{$passengerIndex}.point_dep_id", $pointDep->id);
        }

        return $bookingComponent
            ->set('paymentMethod', 'wave')
            ->call('reviewBooking')
            ->call('acknowledgeSummary')
            ->call('confirmBooking');
    }

    private function createExistingCustomer(string $phoneNumber, ?Ambassador $ambassador = null): Customer
    {
        return Customer::create([
            'prenom' => 'Modou',
            'nom' => 'Fall',
            'phone_number' => $phoneNumber,
            'ambassador_id' => $ambassador?->id,
            'last_active' => now(),
        ]);
    }

    // ---------------------------------------------------------------- reading ?ref=

    public function test_the_ref_query_parameter_prefills_the_ambassador_code_in_lower_case(): void
    {
        ['depart' => $depart] = $this->createBookableDepart();

        Livewire::withQueryParams(['ref' => 'AWA10'])
            ->test(StudentBooking::class, ['depart' => $depart])
            ->assertSet('ambassadorCode', 'awa10');
    }

    public function test_without_ref_there_is_no_ambassador_code(): void
    {
        ['depart' => $depart] = $this->createBookableDepart();

        Livewire::test(StudentBooking::class, ['depart' => $depart])
            ->assertSet('ambassadorCode', null);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedCodes(): array
    {
        return [
            'contains a space' => ['awa 10'],
            'contains markup' => ['<script>alert(1)</script>'],
            'contains a quote' => ["awa'10"],
            'contains a slash' => ['awa/10'],
            'is longer than 50 characters' => [str_repeat('a', 51)],
            'is empty' => [''],
            'is only spaces' => ['   '],
        ];
    }

    #[DataProvider('malformedCodes')]
    public function test_a_malformed_ref_is_dropped(string $malformedCode): void
    {
        ['depart' => $depart] = $this->createBookableDepart();

        Livewire::withQueryParams(['ref' => $malformedCode])
            ->test(StudentBooking::class, ['depart' => $depart])
            ->assertSet('ambassadorCode', null);
    }

    public function test_a_code_synced_from_the_browser_storage_is_normalized(): void
    {
        ['depart' => $depart] = $this->createBookableDepart();

        Livewire::test(StudentBooking::class, ['depart' => $depart])
            ->set('ambassadorCode', 'AwA10')
            ->assertSet('ambassadorCode', 'awa10');
    }

    #[DataProvider('malformedCodes')]
    public function test_a_malformed_code_synced_from_the_browser_storage_is_dropped(string $malformedCode): void
    {
        ['depart' => $depart] = $this->createBookableDepart();

        Livewire::test(StudentBooking::class, ['depart' => $depart])
            ->set('ambassadorCode', $malformedCode)
            ->assertSet('ambassadorCode', null);
    }

    public function test_the_booking_page_restores_a_remembered_code_from_the_browser(): void
    {
        ['depart' => $depart] = $this->createBookableDepart();

        $this->get('http://'.config('app.public_website_domain').'/reserver/'.$depart->id)
            ->assertOk()
            ->assertSee('rememberedAmbassadorCode', false);
    }

    // ---------------------------------------------------------------- attribution of new customers

    public function test_a_new_customer_booking_with_a_valid_ref_is_attributed_to_the_ambassador(): void
    {
        ['depart' => $depart, 'pointDep' => $pointDep] = $this->createBookableDepart();
        $ambassador = Ambassador::factory()->create(['code' => 'AWA10']);
        $phoneNumber = $this->unusedPassengerPhoneNumber();

        $this->bookWithPhoneNumbers($depart, $pointDep, [$phoneNumber], 'awa10')
            ->assertRedirect('https://pay.wave.com/c/cos-test123?a=8000');

        $customer = Customer::where('phone_number', $phoneNumber)->firstOrFail();
        $this->assertSame($ambassador->id, $customer->ambassador_id);
        $this->assertSame($ambassador->id, Booking::where('customer_id', $customer->id)->firstOrFail()->ambassador_id);
    }

    public function test_the_ref_is_matched_whatever_its_case(): void
    {
        ['depart' => $depart, 'pointDep' => $pointDep] = $this->createBookableDepart();
        $ambassador = Ambassador::factory()->create(['code' => 'AWA10']);
        $phoneNumber = $this->unusedPassengerPhoneNumber();

        $this->bookWithPhoneNumbers($depart, $pointDep, [$phoneNumber], 'AwA10');

        $this->assertSame($ambassador->id, Customer::where('phone_number', $phoneNumber)->firstOrFail()->ambassador_id);
    }

    public function test_every_new_passenger_of_a_group_booking_is_attributed_to_the_ambassador(): void
    {
        ['depart' => $depart, 'pointDep' => $pointDep] = $this->createBookableDepart();
        $ambassador = Ambassador::factory()->create(['code' => 'AWA10']);
        $firstPhoneNumber = $this->unusedPassengerPhoneNumber();
        $secondPhoneNumber = $this->unusedPassengerPhoneNumber();

        $this->bookWithPhoneNumbers($depart, $pointDep, [$firstPhoneNumber, $secondPhoneNumber], 'awa10');

        $this->assertSame($ambassador->id, Customer::where('phone_number', $firstPhoneNumber)->firstOrFail()->ambassador_id);
        $this->assertSame($ambassador->id, Customer::where('phone_number', $secondPhoneNumber)->firstOrFail()->ambassador_id);
        $this->assertSame(2, Booking::where('depart_id', $depart->id)->where('ambassador_id', $ambassador->id)->count());
    }

    public function test_a_new_customer_booking_without_ref_has_no_ambassador(): void
    {
        ['depart' => $depart, 'pointDep' => $pointDep] = $this->createBookableDepart();
        Ambassador::factory()->create(['code' => 'AWA10']);
        $phoneNumber = $this->unusedPassengerPhoneNumber();

        $this->bookWithPhoneNumbers($depart, $pointDep, [$phoneNumber], null);

        $customer = Customer::where('phone_number', $phoneNumber)->firstOrFail();
        $this->assertNull($customer->ambassador_id);
        $this->assertNull(Booking::where('customer_id', $customer->id)->firstOrFail()->ambassador_id);
    }

    public function test_an_unknown_ref_never_blocks_the_booking_and_attributes_nobody(): void
    {
        ['depart' => $depart, 'pointDep' => $pointDep] = $this->createBookableDepart();
        $phoneNumber = $this->unusedPassengerPhoneNumber();

        $this->bookWithPhoneNumbers($depart, $pointDep, [$phoneNumber], 'nobody-has-this-code')
            ->assertRedirect('https://pay.wave.com/c/cos-test123?a=8000');

        $customer = Customer::where('phone_number', $phoneNumber)->firstOrFail();
        $this->assertNull($customer->ambassador_id);
        $this->assertNull(Booking::where('customer_id', $customer->id)->firstOrFail()->ambassador_id);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function ambassadorStatesThatCannotAcquireCustomers(): array
    {
        return [
            'pending approval' => ['pendingApproval'],
            'suspended' => ['suspended'],
            'contract expired' => ['withExpiredContract'],
        ];
    }

    #[DataProvider('ambassadorStatesThatCannotAcquireCustomers')]
    public function test_the_code_of_an_ambassador_who_cannot_acquire_customers_is_ignored_without_blocking_the_booking(string $factoryState): void
    {
        ['depart' => $depart, 'pointDep' => $pointDep] = $this->createBookableDepart();
        $ambassador = Ambassador::factory()->{$factoryState}()->create(['code' => 'AWA10']);
        $phoneNumber = $this->unusedPassengerPhoneNumber();

        $this->bookWithPhoneNumbers($depart, $pointDep, [$phoneNumber], 'awa10')
            ->assertRedirect('https://pay.wave.com/c/cos-test123?a=8000');

        $customer = Customer::where('phone_number', $phoneNumber)->firstOrFail();
        $this->assertNull($customer->ambassador_id);
        $this->assertNull(Booking::where('customer_id', $customer->id)->firstOrFail()->ambassador_id);
        $this->assertSame(0, $ambassador->customers()->count());
    }

    // ---------------------------------------------------------------- existing customers & edge cases

    public function test_an_existing_customer_without_ambassador_is_never_attributed_afterwards(): void
    {
        ['depart' => $depart, 'pointDep' => $pointDep] = $this->createBookableDepart();
        Ambassador::factory()->create(['code' => 'AWA10']);
        $phoneNumber = $this->unusedPassengerPhoneNumber();
        $existingCustomer = $this->createExistingCustomer($phoneNumber);

        $this->bookWithPhoneNumbers($depart, $pointDep, [$phoneNumber], 'awa10');

        $this->assertNull($existingCustomer->fresh()->ambassador_id);
    }

    public function test_an_existing_customer_of_another_ambassador_is_never_taken_over(): void
    {
        ['depart' => $depart, 'pointDep' => $pointDep] = $this->createBookableDepart();
        $firstAmbassador = Ambassador::factory()->create(['code' => 'FIRST1']);
        Ambassador::factory()->create(['code' => 'SECOND1']);
        $phoneNumber = $this->unusedPassengerPhoneNumber();
        $existingCustomer = $this->createExistingCustomer($phoneNumber, $firstAmbassador);

        $this->bookWithPhoneNumbers($depart, $pointDep, [$phoneNumber], 'second1');

        $this->assertSame($firstAmbassador->id, $existingCustomer->fresh()->ambassador_id);
    }

    public function test_in_a_mixed_group_only_the_new_passenger_is_attributed(): void
    {
        ['depart' => $depart, 'pointDep' => $pointDep] = $this->createBookableDepart();
        $ambassador = Ambassador::factory()->create(['code' => 'AWA10']);
        $existingPhoneNumber = $this->unusedPassengerPhoneNumber();
        $existingCustomer = $this->createExistingCustomer($existingPhoneNumber);
        $newPhoneNumber = $this->unusedPassengerPhoneNumber();

        $this->bookWithPhoneNumbers($depart, $pointDep, [$existingPhoneNumber, $newPhoneNumber], 'awa10');

        $this->assertNull($existingCustomer->fresh()->ambassador_id);
        $this->assertSame($ambassador->id, Customer::where('phone_number', $newPhoneNumber)->firstOrFail()->ambassador_id);
    }

    public function test_an_ambassador_booking_for_themselves_does_not_become_their_own_customer(): void
    {
        ['depart' => $depart, 'pointDep' => $pointDep] = $this->createBookableDepart();
        $ambassadorPhoneNumber = $this->unusedPassengerPhoneNumber();
        $ambassador = Ambassador::factory()->create(['code' => 'AWA10', 'phone_number' => $ambassadorPhoneNumber]);

        $this->bookWithPhoneNumbers($depart, $pointDep, [$ambassadorPhoneNumber], 'awa10');

        $this->assertNull(Customer::where('phone_number', $ambassadorPhoneNumber)->firstOrFail()->ambassador_id);
        $this->assertSame(0, $ambassador->customers()->count());
    }

    public function test_the_self_referral_guard_still_attributes_the_other_passengers_of_the_group(): void
    {
        ['depart' => $depart, 'pointDep' => $pointDep] = $this->createBookableDepart();
        $ambassadorPhoneNumber = $this->unusedPassengerPhoneNumber();
        $ambassador = Ambassador::factory()->create(['code' => 'AWA10', 'phone_number' => $ambassadorPhoneNumber]);
        $friendPhoneNumber = $this->unusedPassengerPhoneNumber();

        $this->bookWithPhoneNumbers($depart, $pointDep, [$ambassadorPhoneNumber, $friendPhoneNumber], 'awa10');

        $this->assertNull(Customer::where('phone_number', $ambassadorPhoneNumber)->firstOrFail()->ambassador_id);
        $this->assertSame($ambassador->id, Customer::where('phone_number', $friendPhoneNumber)->firstOrFail()->ambassador_id);
    }

    // ---------------------------------------------------------------- money safety

    public function test_booking_through_a_referral_link_creates_no_earning_before_the_ticket_is_paid(): void
    {
        ['depart' => $depart, 'pointDep' => $pointDep] = $this->createBookableDepart();
        $ambassador = Ambassador::factory()->create(['code' => 'AWA10']);

        $this->bookWithPhoneNumbers($depart, $pointDep, [$this->unusedPassengerPhoneNumber()], 'awa10');

        $this->assertSame(0, AmbassadorEarning::where('ambassador_id', $ambassador->id)->count());
    }

    public function test_a_rejected_booking_on_a_closed_departure_creates_no_booking_and_no_earning(): void
    {
        ['depart' => $depart, 'pointDep' => $pointDep] = $this->createBookableDepart();
        $ambassador = Ambassador::factory()->create(['code' => 'AWA10']);
        $depart->update(['closed' => true]);
        $phoneNumber = $this->unusedPassengerPhoneNumber();

        $this->bookWithPhoneNumbers($depart, $pointDep, [$phoneNumber], 'awa10')->assertNoRedirect();

        $this->assertSame(0, Booking::where('depart_id', $depart->id)->count());
        $this->assertSame(0, AmbassadorEarning::where('ambassador_id', $ambassador->id)->count());
    }
}
