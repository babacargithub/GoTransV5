<?php

namespace Tests\Feature;

use App\Enums\AmbassadorEarningStatus;
use App\Enums\AmbassadorEarningType;
use App\Models\Ambassador;
use App\Models\AmbassadorEarning;
use App\Models\AmbassadorPayout;
use App\Models\AppParams;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Depart;
use App\Models\Destination;
use App\Models\PointDep;
use App\Models\Trajet;
use App\Services\AmbassadorEarningService;
use App\Services\AmbassadorPerformanceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AmbassadorEarningServiceTest extends TestCase
{
    use DatabaseTransactions;

    private const NOW = '2030-01-10 12:00:00';

    private const DEPART_DATE = '2030-01-15 08:00:00';

    private const DEPART_DATE_PLUS_ONE_DAY = '2030-01-16 08:00:00';

    private function lifecycleService(): AmbassadorEarningService
    {
        return app(AmbassadorEarningService::class);
    }

    /**
     * @param  array<string, int>  $ambassadorParams
     */
    private function setAmbassadorParams(array $ambassadorParams): void
    {
        $appParams = AppParams::query()->first() ?? new AppParams(['data' => []]);
        $data = collect($appParams->data ?? [])->except([
            'ambassador_acquisition_amount',
            'ambassador_sponsorship_bonus_amount',
            'ambassador_sponsorship_threshold',
        ])->merge($ambassadorParams)->all();
        $appParams->data = $data;
        $appParams->save();
    }

    private function createDepart(string $date = self::DEPART_DATE): Depart
    {
        $trajet = Trajet::create(['name' => 'Caravane '.uniqid(), 'departure_city' => 'DAKAR', 'arrival_city' => 'SAINT-LOUIS']);
        $depart = Depart::create([
            'name' => 'DEPART EARNING '.uniqid(),
            'date' => $date,
            'trajet_id' => $trajet->id,
            'closed' => false,
            'locked' => false,
            'canceled' => false,
        ]);
        $depart->buses()->create([
            'name' => 'Bus Earning Test',
            'nombre_place' => 50,
            'ticket_price' => 4000,
            'gp_ticket_price' => 6000,
            'closed' => false,
        ]);

        return $depart;
    }

    private function createCustomer(?Ambassador $ambassador = null): Customer
    {
        return Customer::create([
            'prenom' => 'Awa',
            'nom' => 'Diop',
            'phone_number' => fake()->unique()->numerify('77#######'),
            'ambassador_id' => $ambassador?->id,
            'last_active' => now(),
        ]);
    }

    private function createBookingFor(Customer $customer, ?Depart $depart = null): Booking
    {
        $depart ??= $this->createDepart();

        return Booking::create([
            'customer_id' => $customer->id,
            'depart_id' => $depart->id,
            'bus_id' => $depart->buses()->firstOrFail()->id,
            'point_dep_id' => PointDep::create(['name' => 'Gare '.uniqid(), 'trajet_id' => $depart->trajet_id])->id,
            'destination_id' => Destination::create(['name' => 'Dakar centre', 'trajet_id' => $depart->trajet_id])->id,
            'paye' => true,
        ]);
    }

    private function createEarning(Ambassador $ambassador, AmbassadorEarningStatus $status, array $attributes = []): AmbassadorEarning
    {
        return AmbassadorEarning::factory()->create(array_merge([
            'ambassador_id' => $ambassador->id,
            'status' => $status,
        ], $attributes));
    }

    // ---------------------------------------------------------------- recordAcquisitionEarning

    public function test_a_paid_booking_of_a_referred_customer_creates_one_pending_acquisition_earning(): void
    {
        $this->travelTo(self::NOW);
        $this->setAmbassadorParams(['ambassador_acquisition_amount' => 600]);
        $ambassador = Ambassador::factory()->create();
        $customer = $this->createCustomer($ambassador);
        $booking = $this->createBookingFor($customer);

        $earning = $this->lifecycleService()->recordAcquisitionEarning($booking);

        $this->assertModelExists($earning);
        $this->assertSame($ambassador->id, $earning->ambassador_id);
        $this->assertSame(AmbassadorEarningType::Acquisition, $earning->type);
        $this->assertSame(AmbassadorEarningStatus::Pending, $earning->status);
        $this->assertSame(600, $earning->amount);
        $this->assertSame($booking->id, $earning->booking_id);
        $this->assertSame("acquisition:{$customer->id}", $earning->idempotency_key);
        $this->assertNull($earning->approved_at);
        $this->assertNull($earning->paid_at);
        $this->assertNull($earning->cancelled_at);
        $this->assertNull($earning->ambassador_payout_id);
        $this->assertSame(1, AmbassadorEarning::where('ambassador_id', $ambassador->id)->count());
    }

    public function test_the_acquisition_earning_becomes_approvable_one_day_after_the_departure_date(): void
    {
        $this->travelTo(self::NOW);
        $booking = $this->createBookingFor($this->createCustomer(Ambassador::factory()->create()));

        $earning = $this->lifecycleService()->recordAcquisitionEarning($booking);

        $this->assertSame(self::DEPART_DATE_PLUS_ONE_DAY, $earning->approvable_at->format('Y-m-d H:i:s'));
    }

    public function test_the_acquisition_amount_falls_back_to_500_when_app_params_define_none(): void
    {
        $this->setAmbassadorParams([]);
        $booking = $this->createBookingFor($this->createCustomer(Ambassador::factory()->create()));

        $earning = $this->lifecycleService()->recordAcquisitionEarning($booking);

        $this->assertSame(500, $earning->amount);
    }

    public function test_changing_the_global_rate_never_rewrites_an_existing_earning(): void
    {
        $this->setAmbassadorParams(['ambassador_acquisition_amount' => 500]);
        $booking = $this->createBookingFor($this->createCustomer(Ambassador::factory()->create()));
        $earning = $this->lifecycleService()->recordAcquisitionEarning($booking);

        $this->setAmbassadorParams(['ambassador_acquisition_amount' => 900]);
        $sameEarning = $this->lifecycleService()->recordAcquisitionEarning($booking);

        $this->assertTrue($sameEarning->is($earning));
        $this->assertSame(500, $earning->fresh()->amount);
    }

    public function test_a_customer_without_ambassador_creates_no_earning(): void
    {
        $booking = $this->createBookingFor($this->createCustomer());
        $earningsCountBefore = AmbassadorEarning::count();

        $result = $this->lifecycleService()->recordAcquisitionEarning($booking);

        $this->assertNull($result);
        $this->assertSame($earningsCountBefore, AmbassadorEarning::count());
    }

    public function test_recording_the_same_booking_twice_never_pays_twice(): void
    {
        $ambassador = Ambassador::factory()->create();
        $booking = $this->createBookingFor($this->createCustomer($ambassador));

        $firstEarning = $this->lifecycleService()->recordAcquisitionEarning($booking);
        $secondEarning = $this->lifecycleService()->recordAcquisitionEarning($booking);

        $this->assertTrue($secondEarning->is($firstEarning));
        $this->assertSame(1, AmbassadorEarning::where('ambassador_id', $ambassador->id)->count());
    }

    public function test_a_second_booking_of_the_same_customer_keeps_the_first_earning_untouched(): void
    {
        $ambassador = Ambassador::factory()->create();
        $customer = $this->createCustomer($ambassador);
        $firstBooking = $this->createBookingFor($customer);
        $secondBooking = $this->createBookingFor($customer);
        $firstEarning = $this->lifecycleService()->recordAcquisitionEarning($firstBooking);

        $returnedEarning = $this->lifecycleService()->recordAcquisitionEarning($secondBooking);

        $this->assertTrue($returnedEarning->is($firstEarning));
        $this->assertSame($firstBooking->id, $firstEarning->fresh()->booking_id);
        $this->assertSame(1, AmbassadorEarning::where('ambassador_id', $ambassador->id)->count());
    }

    public function test_two_different_customers_of_the_same_ambassador_create_two_earnings(): void
    {
        $ambassador = Ambassador::factory()->create();

        $this->lifecycleService()->recordAcquisitionEarning($this->createBookingFor($this->createCustomer($ambassador)));
        $this->lifecycleService()->recordAcquisitionEarning($this->createBookingFor($this->createCustomer($ambassador)));

        $this->assertSame(2, AmbassadorEarning::where('ambassador_id', $ambassador->id)->count());
    }

    public function test_the_earning_goes_to_the_ambassador_of_the_customer_and_not_to_one_of_the_booking(): void
    {
        $customerAmbassador = Ambassador::factory()->create();
        $bookingAmbassador = Ambassador::factory()->create();
        $booking = $this->createBookingFor($this->createCustomer($customerAmbassador));
        $booking->update(['ambassador_id' => $bookingAmbassador->id]);

        $earning = $this->lifecycleService()->recordAcquisitionEarning($booking->fresh());

        $this->assertSame($customerAmbassador->id, $earning->ambassador_id);
        $this->assertSame(0, AmbassadorEarning::where('ambassador_id', $bookingAmbassador->id)->count());
    }

    public function test_an_ambassador_whose_contract_ended_after_the_attribution_is_still_paid_for_that_customer(): void
    {
        $ambassador = Ambassador::factory()->withExpiredContract()->create();
        $booking = $this->createBookingFor($this->createCustomer($ambassador));

        $earning = $this->lifecycleService()->recordAcquisitionEarning($booking);

        $this->assertSame($ambassador->id, $earning->ambassador_id);
    }

    public function test_a_cancelled_acquisition_earning_is_not_recreated_when_the_customer_books_again(): void
    {
        $ambassador = Ambassador::factory()->create();
        $customer = $this->createCustomer($ambassador);
        $refundedBooking = $this->createBookingFor($customer);
        $this->lifecycleService()->recordAcquisitionEarning($refundedBooking);
        $this->lifecycleService()->cancelEarningsOfBooking($refundedBooking);

        $returnedEarning = $this->lifecycleService()->recordAcquisitionEarning($this->createBookingFor($customer));

        $this->assertSame(AmbassadorEarningStatus::Cancelled, $returnedEarning->status);
        $this->assertSame(1, AmbassadorEarning::where('ambassador_id', $ambassador->id)->count());
    }

    // ---------------------------------------------------------------- sponsorship bonus

    private function createAcquisitionEarningsFor(Ambassador $ambassador, int $count): void
    {
        foreach (range(1, $count) as $unused) {
            $this->createEarning($ambassador, AmbassadorEarningStatus::Pending, ['type' => AmbassadorEarningType::Acquisition]);
        }
    }

    public function test_no_sponsorship_bonus_is_created_for_an_ambassador_without_sponsor(): void
    {
        $this->setAmbassadorParams(['ambassador_sponsorship_threshold' => 2]);
        $ambassador = Ambassador::factory()->create();
        $this->createAcquisitionEarningsFor($ambassador, 5);

        $result = $this->lifecycleService()->recordSponsorshipBonusIfThresholdReached($ambassador);

        $this->assertNull($result);
        $this->assertSame(0, AmbassadorEarning::where('type', AmbassadorEarningType::SponsorshipBonus)->count());
    }

    public function test_no_sponsorship_bonus_is_created_one_customer_below_the_threshold(): void
    {
        $this->setAmbassadorParams(['ambassador_sponsorship_threshold' => 15]);
        $sponsor = Ambassador::factory()->create();
        $sponsored = Ambassador::factory()->sponsoredBy($sponsor)->create();
        $this->createAcquisitionEarningsFor($sponsored, 14);

        $result = $this->lifecycleService()->recordSponsorshipBonusIfThresholdReached($sponsored);

        $this->assertNull($result);
        $this->assertSame(0, AmbassadorEarning::where('ambassador_id', $sponsor->id)->count());
    }

    public function test_the_sponsor_gets_one_pending_bonus_when_the_sponsored_ambassador_reaches_the_threshold(): void
    {
        $this->travelTo(self::NOW);
        $this->setAmbassadorParams(['ambassador_sponsorship_threshold' => 15, 'ambassador_sponsorship_bonus_amount' => 2500]);
        $sponsor = Ambassador::factory()->create();
        $sponsored = Ambassador::factory()->sponsoredBy($sponsor)->create();
        $this->createAcquisitionEarningsFor($sponsored, 15);

        $bonus = $this->lifecycleService()->recordSponsorshipBonusIfThresholdReached($sponsored);

        $this->assertModelExists($bonus);
        $this->assertSame($sponsor->id, $bonus->ambassador_id);
        $this->assertSame($sponsored->id, $bonus->source_ambassador_id);
        $this->assertSame(AmbassadorEarningType::SponsorshipBonus, $bonus->type);
        $this->assertSame(AmbassadorEarningStatus::Pending, $bonus->status);
        $this->assertSame(2500, $bonus->amount);
        $this->assertSame("sponsorship:{$sponsored->id}", $bonus->idempotency_key);
        $this->assertNull($bonus->booking_id);
        $this->assertSame(self::NOW, $bonus->approvable_at->format('Y-m-d H:i:s'));
    }

    public function test_the_sponsorship_bonus_amount_and_threshold_fall_back_to_2000_and_15(): void
    {
        $this->setAmbassadorParams([]);
        $sponsor = Ambassador::factory()->create();
        $sponsored = Ambassador::factory()->sponsoredBy($sponsor)->create();
        $this->createAcquisitionEarningsFor($sponsored, 14);
        $this->assertNull($this->lifecycleService()->recordSponsorshipBonusIfThresholdReached($sponsored));

        $this->createAcquisitionEarningsFor($sponsored, 1);
        $bonus = $this->lifecycleService()->recordSponsorshipBonusIfThresholdReached($sponsored);

        $this->assertSame(2000, $bonus->amount);
    }

    public function test_the_sponsorship_bonus_is_paid_only_once_however_many_customers_follow(): void
    {
        $this->setAmbassadorParams(['ambassador_sponsorship_threshold' => 2]);
        $sponsor = Ambassador::factory()->create();
        $sponsored = Ambassador::factory()->sponsoredBy($sponsor)->create();
        $this->createAcquisitionEarningsFor($sponsored, 2);

        $firstBonus = $this->lifecycleService()->recordSponsorshipBonusIfThresholdReached($sponsored);
        $this->createAcquisitionEarningsFor($sponsored, 3);
        $secondBonus = $this->lifecycleService()->recordSponsorshipBonusIfThresholdReached($sponsored);

        $this->assertTrue($secondBonus->is($firstBonus));
        $this->assertSame(1, AmbassadorEarning::where('type', AmbassadorEarningType::SponsorshipBonus)->where('ambassador_id', $sponsor->id)->count());
    }

    public function test_the_sponsor_is_not_rewarded_again_when_the_threshold_is_reached_a_second_time_after_a_cancellation(): void
    {
        $this->setAmbassadorParams(['ambassador_sponsorship_threshold' => 3]);
        $sponsor = Ambassador::factory()->create();
        $sponsored = Ambassador::factory()->sponsoredBy($sponsor)->create();
        $this->createAcquisitionEarningsFor($sponsored, 3);
        $firstBonus = $this->lifecycleService()->recordSponsorshipBonusIfThresholdReached($sponsored);
        $firstBonus->update(['status' => AmbassadorEarningStatus::Paid, 'paid_at' => now()]);

        $sponsored->earnings()->where('type', AmbassadorEarningType::Acquisition)->first()->update([
            'status' => AmbassadorEarningStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
        $this->assertNull($this->lifecycleService()->recordSponsorshipBonusIfThresholdReached($sponsored));

        $this->createAcquisitionEarningsFor($sponsored, 1);
        $bonusAfterThresholdReachedAgain = $this->lifecycleService()->recordSponsorshipBonusIfThresholdReached($sponsored);

        $this->assertTrue($bonusAfterThresholdReachedAgain->is($firstBonus));
        $this->assertFalse($bonusAfterThresholdReachedAgain->wasRecentlyCreated);
        $this->assertSame(1, AmbassadorEarning::where('type', AmbassadorEarningType::SponsorshipBonus)->where('ambassador_id', $sponsor->id)->count());
    }

    public function test_cancelled_acquisitions_do_not_count_towards_the_sponsorship_threshold(): void
    {
        $this->setAmbassadorParams(['ambassador_sponsorship_threshold' => 3]);
        $sponsor = Ambassador::factory()->create();
        $sponsored = Ambassador::factory()->sponsoredBy($sponsor)->create();
        $this->createAcquisitionEarningsFor($sponsored, 2);
        $this->createEarning($sponsored, AmbassadorEarningStatus::Cancelled, ['type' => AmbassadorEarningType::Acquisition, 'cancelled_at' => now()]);

        $result = $this->lifecycleService()->recordSponsorshipBonusIfThresholdReached($sponsored);

        $this->assertNull($result);
    }

    public function test_approved_and_paid_acquisitions_count_towards_the_sponsorship_threshold(): void
    {
        $this->setAmbassadorParams(['ambassador_sponsorship_threshold' => 3]);
        $sponsor = Ambassador::factory()->create();
        $sponsored = Ambassador::factory()->sponsoredBy($sponsor)->create();
        $this->createEarning($sponsored, AmbassadorEarningStatus::Approved, ['type' => AmbassadorEarningType::Acquisition]);
        $this->createEarning($sponsored, AmbassadorEarningStatus::Paid, ['type' => AmbassadorEarningType::Acquisition, 'paid_at' => now()]);
        $this->createEarning($sponsored, AmbassadorEarningStatus::Pending, ['type' => AmbassadorEarningType::Acquisition]);

        $bonus = $this->lifecycleService()->recordSponsorshipBonusIfThresholdReached($sponsored);

        $this->assertModelExists($bonus);
    }

    public function test_sponsorship_bonuses_of_the_sponsored_ambassador_do_not_count_as_acquired_customers(): void
    {
        $this->setAmbassadorParams(['ambassador_sponsorship_threshold' => 2]);
        $sponsor = Ambassador::factory()->create();
        $sponsored = Ambassador::factory()->sponsoredBy($sponsor)->create();
        $this->createAcquisitionEarningsFor($sponsored, 1);
        $this->createEarning($sponsored, AmbassadorEarningStatus::Approved, [
            'type' => AmbassadorEarningType::SponsorshipBonus,
            'source_ambassador_id' => Ambassador::factory()->create()->id,
        ]);

        $result = $this->lifecycleService()->recordSponsorshipBonusIfThresholdReached($sponsored);

        $this->assertNull($result);
    }

    public function test_acquisitions_of_another_ambassador_do_not_count_towards_the_threshold(): void
    {
        $this->setAmbassadorParams(['ambassador_sponsorship_threshold' => 2]);
        $sponsor = Ambassador::factory()->create();
        $sponsored = Ambassador::factory()->sponsoredBy($sponsor)->create();
        $this->createAcquisitionEarningsFor($sponsored, 1);
        $this->createAcquisitionEarningsFor(Ambassador::factory()->create(), 10);

        $result = $this->lifecycleService()->recordSponsorshipBonusIfThresholdReached($sponsored);

        $this->assertNull($result);
    }

    public function test_the_acquisition_that_reaches_the_threshold_triggers_the_sponsors_bonus(): void
    {
        $this->setAmbassadorParams(['ambassador_sponsorship_threshold' => 3, 'ambassador_sponsorship_bonus_amount' => 2000]);
        $sponsor = Ambassador::factory()->create();
        $sponsored = Ambassador::factory()->sponsoredBy($sponsor)->create();
        $this->createAcquisitionEarningsFor($sponsored, 2);
        $booking = $this->createBookingFor($this->createCustomer($sponsored));

        $this->lifecycleService()->recordAcquisitionEarning($booking);

        $bonus = AmbassadorEarning::where('ambassador_id', $sponsor->id)->where('type', AmbassadorEarningType::SponsorshipBonus)->firstOrFail();
        $this->assertSame(2000, $bonus->amount);
        $this->assertSame($sponsored->id, $bonus->source_ambassador_id);
    }

    public function test_an_acquisition_below_the_threshold_does_not_trigger_any_bonus(): void
    {
        $this->setAmbassadorParams(['ambassador_sponsorship_threshold' => 3]);
        $sponsor = Ambassador::factory()->create();
        $sponsored = Ambassador::factory()->sponsoredBy($sponsor)->create();
        $this->createAcquisitionEarningsFor($sponsored, 1);

        $this->lifecycleService()->recordAcquisitionEarning($this->createBookingFor($this->createCustomer($sponsored)));

        $this->assertSame(0, AmbassadorEarning::where('ambassador_id', $sponsor->id)->count());
    }

    public function test_re_recording_an_existing_acquisition_never_creates_a_second_bonus(): void
    {
        $this->setAmbassadorParams(['ambassador_sponsorship_threshold' => 1]);
        $sponsor = Ambassador::factory()->create();
        $sponsored = Ambassador::factory()->sponsoredBy($sponsor)->create();
        $booking = $this->createBookingFor($this->createCustomer($sponsored));

        $this->lifecycleService()->recordAcquisitionEarning($booking);
        $this->lifecycleService()->recordAcquisitionEarning($booking);

        $this->assertSame(1, AmbassadorEarning::where('ambassador_id', $sponsor->id)->count());
    }

    public function test_dropping_below_the_threshold_after_a_cancellation_and_coming_back_never_duplicates_the_bonus(): void
    {
        $this->setAmbassadorParams(['ambassador_sponsorship_threshold' => 2]);
        $sponsor = Ambassador::factory()->create();
        $sponsored = Ambassador::factory()->sponsoredBy($sponsor)->create();
        $firstBooking = $this->createBookingFor($this->createCustomer($sponsored));
        $this->lifecycleService()->recordAcquisitionEarning($firstBooking);
        $this->lifecycleService()->recordAcquisitionEarning($this->createBookingFor($this->createCustomer($sponsored)));
        $this->lifecycleService()->cancelEarningsOfBooking($firstBooking);

        $this->lifecycleService()->recordAcquisitionEarning($this->createBookingFor($this->createCustomer($sponsored)));

        $this->assertSame(1, AmbassadorEarning::where('ambassador_id', $sponsor->id)->where('type', AmbassadorEarningType::SponsorshipBonus)->count());
    }

    // ---------------------------------------------------------------- approveDueEarnings

    public function test_pending_earnings_whose_waiting_period_is_over_become_approved(): void
    {
        $this->travelTo(self::NOW);
        $ambassador = Ambassador::factory()->create();
        $dueEarning = $this->createEarning($ambassador, AmbassadorEarningStatus::Pending, ['approvable_at' => now()->subMinute()]);

        $approvedCount = $this->lifecycleService()->approveDueEarnings();

        $this->assertSame(1, $approvedCount);
        $this->assertSame(AmbassadorEarningStatus::Approved, $dueEarning->fresh()->status);
        $this->assertSame(self::NOW, $dueEarning->fresh()->approved_at->format('Y-m-d H:i:s'));
    }

    public function test_an_earning_due_exactly_now_is_approved(): void
    {
        $this->freezeTime();
        $earning = $this->createEarning(Ambassador::factory()->create(), AmbassadorEarningStatus::Pending, ['approvable_at' => now()]);

        $this->lifecycleService()->approveDueEarnings();

        $this->assertSame(AmbassadorEarningStatus::Approved, $earning->fresh()->status);
    }

    public function test_an_earning_due_one_second_from_now_stays_pending(): void
    {
        $this->freezeTime();
        $earning = $this->createEarning(Ambassador::factory()->create(), AmbassadorEarningStatus::Pending, ['approvable_at' => now()->addSecond()]);

        $this->lifecycleService()->approveDueEarnings();

        $this->assertSame(AmbassadorEarningStatus::Pending, $earning->fresh()->status);
        $this->assertNull($earning->fresh()->approved_at);
    }

    public function test_an_earning_without_approvable_date_is_never_approved(): void
    {
        $earning = $this->createEarning(Ambassador::factory()->create(), AmbassadorEarningStatus::Pending, ['approvable_at' => null]);

        $this->lifecycleService()->approveDueEarnings();

        $this->assertSame(AmbassadorEarningStatus::Pending, $earning->fresh()->status);
    }

    public function test_cancelled_earnings_are_never_resurrected_by_the_approval_run(): void
    {
        $earning = $this->createEarning(Ambassador::factory()->create(), AmbassadorEarningStatus::Cancelled, [
            'approvable_at' => now()->subDay(),
            'cancelled_at' => now()->subHour(),
        ]);

        $approvedCount = $this->lifecycleService()->approveDueEarnings();

        $this->assertSame(0, $approvedCount);
        $this->assertSame(AmbassadorEarningStatus::Cancelled, $earning->fresh()->status);
        $this->assertNull($earning->fresh()->approved_at);
    }

    public function test_paid_and_already_approved_earnings_are_left_untouched_by_the_approval_run(): void
    {
        $this->travelTo(self::NOW);
        $ambassador = Ambassador::factory()->create();
        $alreadyApproved = $this->createEarning($ambassador, AmbassadorEarningStatus::Approved, [
            'approvable_at' => now()->subDays(3),
            'approved_at' => now()->subDays(2),
        ]);
        $paid = $this->createEarning($ambassador, AmbassadorEarningStatus::Paid, [
            'approvable_at' => now()->subDays(9),
            'approved_at' => now()->subDays(8),
            'paid_at' => now()->subDays(7),
        ]);

        $approvedCount = $this->lifecycleService()->approveDueEarnings();

        $this->assertSame(0, $approvedCount);
        $this->assertSame('2030-01-08 12:00:00', $alreadyApproved->fresh()->approved_at->format('Y-m-d H:i:s'));
        $this->assertSame(AmbassadorEarningStatus::Paid, $paid->fresh()->status);
        $this->assertSame('2030-01-02 12:00:00', $paid->fresh()->approved_at->format('Y-m-d H:i:s'));
    }

    public function test_the_approval_run_is_idempotent(): void
    {
        $this->travelTo(self::NOW);
        $earning = $this->createEarning(Ambassador::factory()->create(), AmbassadorEarningStatus::Pending, ['approvable_at' => now()->subHour()]);
        $this->lifecycleService()->approveDueEarnings();
        $this->travel(2)->hours();

        $secondRunCount = $this->lifecycleService()->approveDueEarnings();

        $this->assertSame(0, $secondRunCount);
        $this->assertSame(self::NOW, $earning->fresh()->approved_at->format('Y-m-d H:i:s'));
    }

    public function test_the_approval_run_approves_every_ambassadors_due_earnings_and_only_the_due_ones(): void
    {
        $this->travelTo(self::NOW);
        $ambassador = Ambassador::factory()->create();
        $otherAmbassador = Ambassador::factory()->create();
        $dueEarning = $this->createEarning($ambassador, AmbassadorEarningStatus::Pending, ['approvable_at' => now()->subHour()]);
        $otherDueEarning = $this->createEarning($otherAmbassador, AmbassadorEarningStatus::Pending, ['approvable_at' => now()->subDay()]);
        $notDueEarning = $this->createEarning($ambassador, AmbassadorEarningStatus::Pending, ['approvable_at' => now()->addHour()]);

        $this->lifecycleService()->approveDueEarnings();

        $this->assertSame(AmbassadorEarningStatus::Approved, $dueEarning->fresh()->status);
        $this->assertSame(AmbassadorEarningStatus::Approved, $otherDueEarning->fresh()->status);
        $this->assertSame(AmbassadorEarningStatus::Pending, $notDueEarning->fresh()->status);
    }

    public function test_approval_moves_the_amount_from_pending_to_available_without_changing_the_total(): void
    {
        $ambassador = Ambassador::factory()->create();
        $this->createEarning($ambassador, AmbassadorEarningStatus::Pending, ['amount' => 750, 'approvable_at' => now()->subHour()]);
        $balanceBefore = app(AmbassadorPerformanceService::class)->getAccountBalance($ambassador);

        $this->lifecycleService()->approveDueEarnings();

        $balanceAfter = app(AmbassadorPerformanceService::class)->getAccountBalance($ambassador);
        $this->assertSame([750, 0, 750], [$balanceBefore->totalBalance, $balanceBefore->availableBalance, $balanceBefore->pendingBalance]);
        $this->assertSame([750, 750, 0], [$balanceAfter->totalBalance, $balanceAfter->availableBalance, $balanceAfter->pendingBalance]);
    }

    // ---------------------------------------------------------------- cancelEarningsOfBooking

    public function test_cancelling_a_booking_cancels_its_pending_earning(): void
    {
        $this->travelTo(self::NOW);
        $booking = $this->createBookingFor($this->createCustomer(Ambassador::factory()->create()));
        $earning = $this->lifecycleService()->recordAcquisitionEarning($booking);

        $cancelledCount = $this->lifecycleService()->cancelEarningsOfBooking($booking);

        $this->assertSame(1, $cancelledCount);
        $this->assertSame(AmbassadorEarningStatus::Cancelled, $earning->fresh()->status);
        $this->assertSame(self::NOW, $earning->fresh()->cancelled_at->format('Y-m-d H:i:s'));
    }

    public function test_cancelling_a_booking_cancels_its_approved_unpaid_earning(): void
    {
        $ambassador = Ambassador::factory()->create();
        $booking = $this->createBookingFor($this->createCustomer($ambassador));
        $earning = $this->createEarning($ambassador, AmbassadorEarningStatus::Approved, ['booking_id' => $booking->id]);

        $cancelledCount = $this->lifecycleService()->cancelEarningsOfBooking($booking);

        $this->assertSame(1, $cancelledCount);
        $this->assertSame(AmbassadorEarningStatus::Cancelled, $earning->fresh()->status);
    }

    public function test_a_paid_earning_can_never_be_cancelled(): void
    {
        $ambassador = Ambassador::factory()->create();
        $booking = $this->createBookingFor($this->createCustomer($ambassador));
        $earning = $this->createEarning($ambassador, AmbassadorEarningStatus::Paid, ['booking_id' => $booking->id, 'paid_at' => now()]);

        $cancelledCount = $this->lifecycleService()->cancelEarningsOfBooking($booking);

        $this->assertSame(0, $cancelledCount);
        $this->assertSame(AmbassadorEarningStatus::Paid, $earning->fresh()->status);
        $this->assertNull($earning->fresh()->cancelled_at);
    }

    public function test_an_approved_earning_already_attached_to_a_payout_can_not_be_cancelled(): void
    {
        $ambassador = Ambassador::factory()->create();
        $booking = $this->createBookingFor($this->createCustomer($ambassador));
        $payout = AmbassadorPayout::factory()->create(['ambassador_id' => $ambassador->id]);
        $earning = $this->createEarning($ambassador, AmbassadorEarningStatus::Approved, [
            'booking_id' => $booking->id,
            'ambassador_payout_id' => $payout->id,
        ]);

        $cancelledCount = $this->lifecycleService()->cancelEarningsOfBooking($booking);

        $this->assertSame(0, $cancelledCount);
        $this->assertSame(AmbassadorEarningStatus::Approved, $earning->fresh()->status);
    }

    public function test_cancelling_a_booking_leaves_the_earnings_of_other_bookings_untouched(): void
    {
        $ambassador = Ambassador::factory()->create();
        $bookingToCancel = $this->createBookingFor($this->createCustomer($ambassador));
        $otherBooking = $this->createBookingFor($this->createCustomer($ambassador));
        $this->lifecycleService()->recordAcquisitionEarning($bookingToCancel);
        $otherEarning = $this->lifecycleService()->recordAcquisitionEarning($otherBooking);

        $this->lifecycleService()->cancelEarningsOfBooking($bookingToCancel);

        $this->assertSame(AmbassadorEarningStatus::Pending, $otherEarning->fresh()->status);
    }

    public function test_cancelling_a_booking_without_earning_changes_nothing(): void
    {
        $booking = $this->createBookingFor($this->createCustomer());
        $ambassador = Ambassador::factory()->create();
        $unrelatedEarning = $this->createEarning($ambassador, AmbassadorEarningStatus::Pending, ['booking_id' => null]);

        $cancelledCount = $this->lifecycleService()->cancelEarningsOfBooking($booking);

        $this->assertSame(0, $cancelledCount);
        $this->assertSame(AmbassadorEarningStatus::Pending, $unrelatedEarning->fresh()->status);
    }

    public function test_cancelling_twice_keeps_the_first_cancellation_date(): void
    {
        $this->travelTo(self::NOW);
        $booking = $this->createBookingFor($this->createCustomer(Ambassador::factory()->create()));
        $earning = $this->lifecycleService()->recordAcquisitionEarning($booking);
        $this->lifecycleService()->cancelEarningsOfBooking($booking);
        $this->travel(3)->days();

        $secondCount = $this->lifecycleService()->cancelEarningsOfBooking($booking);

        $this->assertSame(0, $secondCount);
        $this->assertSame(self::NOW, $earning->fresh()->cancelled_at->format('Y-m-d H:i:s'));
    }

    public function test_cancelling_a_booking_never_touches_a_sponsorship_bonus(): void
    {
        $ambassador = Ambassador::factory()->create();
        $booking = $this->createBookingFor($this->createCustomer());
        $bonus = $this->createEarning($ambassador, AmbassadorEarningStatus::Pending, [
            'type' => AmbassadorEarningType::SponsorshipBonus,
            'booking_id' => null,
            'source_ambassador_id' => Ambassador::factory()->create()->id,
        ]);

        $this->lifecycleService()->cancelEarningsOfBooking($booking);

        $this->assertSame(AmbassadorEarningStatus::Pending, $bonus->fresh()->status);
    }

    public function test_cancelling_removes_the_amount_from_the_balance(): void
    {
        $ambassador = Ambassador::factory()->create();
        $booking = $this->createBookingFor($this->createCustomer($ambassador));
        $this->lifecycleService()->recordAcquisitionEarning($booking);
        $otherBooking = $this->createBookingFor($this->createCustomer($ambassador));
        $this->lifecycleService()->recordAcquisitionEarning($otherBooking);

        $this->lifecycleService()->cancelEarningsOfBooking($booking);

        $balance = app(AmbassadorPerformanceService::class)->getAccountBalance($ambassador);
        $this->assertSame(500, $balance->totalBalance);
    }

    // ---------------------------------------------------------------- rescheduleEarningsOfTransferredBooking

    public function test_a_transferred_booking_pending_earning_is_postponed_to_one_day_after_the_new_departure(): void
    {
        $this->travelTo(self::NOW);
        $booking = $this->createBookingFor($this->createCustomer(Ambassador::factory()->create()));
        $earning = $this->lifecycleService()->recordAcquisitionEarning($booking);
        $newDepart = $this->createDepart('2030-02-20 06:30:00');
        $booking->update(['depart_id' => $newDepart->id, 'bus_id' => $newDepart->buses()->firstOrFail()->id]);

        $rescheduledCount = $this->lifecycleService()->rescheduleEarningsOfTransferredBooking($booking->fresh());

        $this->assertSame(1, $rescheduledCount);
        $this->assertSame('2030-02-21 06:30:00', $earning->fresh()->approvable_at->format('Y-m-d H:i:s'));
        $this->assertSame(AmbassadorEarningStatus::Pending, $earning->fresh()->status);
    }

    public function test_a_transferred_booking_keeps_its_earning_amount_and_identity(): void
    {
        $booking = $this->createBookingFor($this->createCustomer(Ambassador::factory()->create()));
        $earning = $this->lifecycleService()->recordAcquisitionEarning($booking);
        $newDepart = $this->createDepart('2030-02-20 06:30:00');
        $booking->update(['depart_id' => $newDepart->id]);

        $this->lifecycleService()->rescheduleEarningsOfTransferredBooking($booking->fresh());

        $this->assertSame($earning->amount, $earning->fresh()->amount);
        $this->assertSame($earning->idempotency_key, $earning->fresh()->idempotency_key);
        $this->assertSame($booking->id, $earning->fresh()->booking_id);
    }

    public function test_a_transfer_never_changes_an_approved_paid_or_cancelled_earning(): void
    {
        $ambassador = Ambassador::factory()->create();
        $booking = $this->createBookingFor($this->createCustomer($ambassador));
        $originalApprovableAt = now()->subDays(2)->startOfSecond();
        $approved = $this->createEarning($ambassador, AmbassadorEarningStatus::Approved, ['booking_id' => $booking->id, 'approvable_at' => $originalApprovableAt]);
        $paid = $this->createEarning($ambassador, AmbassadorEarningStatus::Paid, ['booking_id' => $booking->id, 'approvable_at' => $originalApprovableAt, 'paid_at' => now()]);
        $cancelled = $this->createEarning($ambassador, AmbassadorEarningStatus::Cancelled, ['booking_id' => $booking->id, 'approvable_at' => $originalApprovableAt, 'cancelled_at' => now()]);
        $booking->update(['depart_id' => $this->createDepart('2031-05-05 10:00:00')->id]);

        $rescheduledCount = $this->lifecycleService()->rescheduleEarningsOfTransferredBooking($booking->fresh());

        $this->assertSame(0, $rescheduledCount);
        foreach ([$approved, $paid, $cancelled] as $untouchedEarning) {
            $this->assertSame($originalApprovableAt->format('Y-m-d H:i:s'), $untouchedEarning->fresh()->approvable_at->format('Y-m-d H:i:s'));
        }
    }

    public function test_a_transfer_leaves_the_earnings_of_other_bookings_untouched(): void
    {
        $ambassador = Ambassador::factory()->create();
        $transferredBooking = $this->createBookingFor($this->createCustomer($ambassador));
        $otherBooking = $this->createBookingFor($this->createCustomer($ambassador));
        $this->lifecycleService()->recordAcquisitionEarning($transferredBooking);
        $otherEarning = $this->lifecycleService()->recordAcquisitionEarning($otherBooking);
        $transferredBooking->update(['depart_id' => $this->createDepart('2031-05-05 10:00:00')->id]);

        $this->lifecycleService()->rescheduleEarningsOfTransferredBooking($transferredBooking->fresh());

        $this->assertSame(self::DEPART_DATE_PLUS_ONE_DAY, $otherEarning->fresh()->approvable_at->format('Y-m-d H:i:s'));
    }

    public function test_a_postponed_earning_is_not_approved_before_the_new_waiting_period_ends(): void
    {
        $this->travelTo('2030-01-17 00:00:00');
        $booking = $this->createBookingFor($this->createCustomer(Ambassador::factory()->create()));
        $earning = $this->lifecycleService()->recordAcquisitionEarning($booking);
        $booking->update(['depart_id' => $this->createDepart('2030-02-20 06:30:00')->id]);
        $this->lifecycleService()->rescheduleEarningsOfTransferredBooking($booking->fresh());

        $this->lifecycleService()->approveDueEarnings();
        $this->assertSame(AmbassadorEarningStatus::Pending, $earning->fresh()->status);

        $this->travelTo('2030-02-21 06:30:00');
        $this->lifecycleService()->approveDueEarnings();
        $this->assertSame(AmbassadorEarningStatus::Approved, $earning->fresh()->status);
    }

    public function test_transferring_a_booking_without_earning_changes_nothing(): void
    {
        $booking = $this->createBookingFor($this->createCustomer());

        $rescheduledCount = $this->lifecycleService()->rescheduleEarningsOfTransferredBooking($booking);

        $this->assertSame(0, $rescheduledCount);
    }
}
