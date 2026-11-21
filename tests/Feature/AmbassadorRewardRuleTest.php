<?php

namespace Tests\Feature;

use App\Enums\AmbassadorEarningStatus;
use App\Enums\AmbassadorEarningType;
use App\Enums\PermissionName;
use App\Livewire\BackOffice\AmbassadorEarningsDialog;
use App\Livewire\BackOffice\AmbassadorList;
use App\Livewire\BackOffice\AmbassadorRewardRuleList;
use App\Models\Ambassador;
use App\Models\AmbassadorEarning;
use App\Models\AmbassadorRewardRule;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Depart;
use App\Models\Destination;
use App\Models\PointDep;
use App\Models\Trajet;
use App\Services\AmbassadorEarningService;
use Database\Seeders\AmbassadorRewardRuleSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class AmbassadorRewardRuleTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        AmbassadorRewardRule::query()->delete();
    }

    private function createDepart(): Depart
    {
        $trajet = Trajet::create(['name' => 'Caravane '.uniqid(), 'departure_city' => 'DAKAR', 'arrival_city' => 'SAINT-LOUIS']);
        $depart = Depart::create([
            'name' => 'DEPART RULE '.uniqid(),
            'date' => now()->addDays(5),
            'trajet_id' => $trajet->id,
            'closed' => false,
            'locked' => false,
            'canceled' => false,
        ]);
        $depart->buses()->create([
            'name' => 'Bus Rule Test',
            'nombre_place' => 50,
            'ticket_price' => 4000,
            'gp_ticket_price' => 6000,
            'closed' => false,
        ]);

        return $depart;
    }

    private function createCustomer(?Ambassador $acquiringAmbassador = null): Customer
    {
        return Customer::create([
            'prenom' => 'Awa',
            'nom' => 'Diop',
            'phone_number' => fake()->unique()->numerify('77#######'),
            'ambassador_id' => $acquiringAmbassador?->id,
            'last_active' => now(),
        ]);
    }

    private function createPaidBookingMadeThroughLinkOf(?Ambassador $referringAmbassador, Customer $customer): Booking
    {
        $depart = $this->createDepart();

        return Booking::create([
            'customer_id' => $customer->id,
            'ambassador_id' => $referringAmbassador?->id,
            'depart_id' => $depart->id,
            'bus_id' => $depart->buses()->firstOrFail()->id,
            'point_dep_id' => PointDep::create(['name' => 'Gare '.uniqid(), 'trajet_id' => $depart->trajet_id])->id,
            'destination_id' => Destination::create(['name' => 'Dakar centre', 'trajet_id' => $depart->trajet_id])->id,
            'paye' => true,
        ]);
    }

    private function earningService(): AmbassadorEarningService
    {
        return app(AmbassadorEarningService::class);
    }

    // ---------------------------------------------------------------- rule resolution

    public function test_a_rule_dedicated_to_an_ambassador_takes_precedence_over_the_general_rule(): void
    {
        $ambassador = Ambassador::factory()->create();
        AmbassadorRewardRule::factory()->create(['amount' => 500]);
        AmbassadorRewardRule::factory()->forAmbassador($ambassador)->create(['amount' => 800]);

        $this->assertSame(800, AmbassadorRewardRule::findApplicableRule(AmbassadorEarningType::Acquisition, $ambassador)->amount);
        $this->assertSame(500, AmbassadorRewardRule::findApplicableRule(AmbassadorEarningType::Acquisition, Ambassador::factory()->create())->amount);
    }

    public function test_paused_and_out_of_period_rules_do_not_apply(): void
    {
        $ambassador = Ambassador::factory()->create();
        AmbassadorRewardRule::factory()->paused()->create();
        AmbassadorRewardRule::factory()->create(['starts_at' => now()->addDay()]);
        AmbassadorRewardRule::factory()->create(['ends_at' => now()->subDay()]);

        $this->assertNull(AmbassadorRewardRule::findApplicableRule(AmbassadorEarningType::Acquisition, $ambassador));
    }

    public function test_a_paused_dedicated_rule_stops_the_reward_for_that_ambassador_despite_the_general_rule(): void
    {
        $pausedAmbassador = Ambassador::factory()->create();
        AmbassadorRewardRule::factory()->create(['amount' => 500]);
        AmbassadorRewardRule::factory()->forAmbassador($pausedAmbassador)->paused()->create();

        $this->assertNull(AmbassadorRewardRule::findApplicableRule(AmbassadorEarningType::Acquisition, $pausedAmbassador));
        $this->assertSame(500, AmbassadorRewardRule::findApplicableRule(AmbassadorEarningType::Acquisition, Ambassador::factory()->create())->amount);
    }

    public function test_a_dedicated_rule_outside_its_period_lets_the_general_rule_apply(): void
    {
        $ambassador = Ambassador::factory()->create();
        AmbassadorRewardRule::factory()->create(['amount' => 500]);
        AmbassadorRewardRule::factory()->forAmbassador($ambassador)->paused()->create(['ends_at' => now()->subDay()]);

        $this->assertSame(500, AmbassadorRewardRule::findApplicableRule(AmbassadorEarningType::Acquisition, $ambassador)->amount);
    }

    public function test_a_paused_general_rule_does_not_stop_an_ambassador_with_an_enabled_dedicated_rule(): void
    {
        $ambassador = Ambassador::factory()->create();
        AmbassadorRewardRule::factory()->paused()->create();
        AmbassadorRewardRule::factory()->forAmbassador($ambassador)->create(['amount' => 80]);

        $this->assertSame(80, AmbassadorRewardRule::findApplicableRule(AmbassadorEarningType::Acquisition, $ambassador)->amount);
    }

    public function test_a_rule_applies_inside_its_period(): void
    {
        $ambassador = Ambassador::factory()->create();
        AmbassadorRewardRule::factory()->create(['starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);

        $this->assertNotNull(AmbassadorRewardRule::findApplicableRule(AmbassadorEarningType::Acquisition, $ambassador));
    }

    // ---------------------------------------------------------------- existing customer reward

    public function test_a_booking_of_an_existing_customer_through_an_ambassador_link_earns_the_rule_amount(): void
    {
        $ambassador = Ambassador::factory()->create();
        AmbassadorRewardRule::factory()->forExistingCustomerBookings()->create(['amount' => 50]);
        $booking = $this->createPaidBookingMadeThroughLinkOf($ambassador, $this->createCustomer());

        $earning = $this->earningService()->recordExistingCustomerBookingEarning($booking);

        $this->assertSame(AmbassadorEarningType::ExistingCustomerBooking, $earning->type);
        $this->assertSame(AmbassadorEarningStatus::Pending, $earning->status);
        $this->assertSame(50, $earning->amount);
        $this->assertSame($ambassador->id, $earning->ambassador_id);
        $this->assertSame($booking->id, $earning->booking_id);
        $this->assertSame("existing_booking:{$booking->id}", $earning->idempotency_key);
    }

    public function test_the_same_booking_is_never_rewarded_twice(): void
    {
        AmbassadorRewardRule::factory()->forExistingCustomerBookings()->create();
        $booking = $this->createPaidBookingMadeThroughLinkOf(Ambassador::factory()->create(), $this->createCustomer());

        $this->earningService()->recordExistingCustomerBookingEarning($booking);
        $this->earningService()->recordExistingCustomerBookingEarning($booking);

        $this->assertSame(1, AmbassadorEarning::where('booking_id', $booking->id)->count());
    }

    public function test_nothing_is_earned_without_an_applicable_existing_customer_rule(): void
    {
        AmbassadorRewardRule::factory()->forExistingCustomerBookings()->paused()->create();
        $booking = $this->createPaidBookingMadeThroughLinkOf(Ambassador::factory()->create(), $this->createCustomer());

        $this->assertNull($this->earningService()->recordExistingCustomerBookingEarning($booking));
        $this->assertSame(0, AmbassadorEarning::where('booking_id', $booking->id)->count());
    }

    public function test_a_rule_dedicated_to_another_ambassador_does_not_reward_this_one(): void
    {
        AmbassadorRewardRule::factory()->forExistingCustomerBookings()->forAmbassador(Ambassador::factory()->create())->create();
        $booking = $this->createPaidBookingMadeThroughLinkOf(Ambassador::factory()->create(), $this->createCustomer());

        $this->assertNull($this->earningService()->recordExistingCustomerBookingEarning($booking));
    }

    public function test_a_customer_acquired_by_the_same_ambassador_is_not_rewarded_again_as_existing(): void
    {
        AmbassadorRewardRule::factory()->forExistingCustomerBookings()->create();
        $ambassador = Ambassador::factory()->create();
        $booking = $this->createPaidBookingMadeThroughLinkOf($ambassador, $this->createCustomer($ambassador));

        $this->assertNull($this->earningService()->recordExistingCustomerBookingEarning($booking));
    }

    public function test_a_customer_acquired_by_another_ambassador_still_rewards_the_ambassador_whose_link_was_used(): void
    {
        AmbassadorRewardRule::factory()->forExistingCustomerBookings()->create();
        $referringAmbassador = Ambassador::factory()->create();
        $booking = $this->createPaidBookingMadeThroughLinkOf($referringAmbassador, $this->createCustomer(Ambassador::factory()->create()));

        $earning = $this->earningService()->recordExistingCustomerBookingEarning($booking);

        $this->assertSame($referringAmbassador->id, $earning->ambassador_id);
    }

    public function test_an_ambassador_booking_for_themselves_earns_nothing(): void
    {
        AmbassadorRewardRule::factory()->forExistingCustomerBookings()->create();
        $ambassador = Ambassador::factory()->create();
        $ambassadorAsCustomer = Customer::create([
            'prenom' => 'Moi',
            'nom' => 'Meme',
            'phone_number' => $ambassador->phone_number,
            'last_active' => now(),
        ]);
        $booking = $this->createPaidBookingMadeThroughLinkOf($ambassador, $ambassadorAsCustomer);

        $this->assertNull($this->earningService()->recordExistingCustomerBookingEarning($booking));
    }

    public function test_a_booking_without_ambassador_link_earns_nothing(): void
    {
        AmbassadorRewardRule::factory()->forExistingCustomerBookings()->create();
        $booking = $this->createPaidBookingMadeThroughLinkOf(null, $this->createCustomer());

        $this->assertNull($this->earningService()->recordExistingCustomerBookingEarning($booking));
    }

    public function test_cancelling_the_booking_voids_the_existing_customer_earning(): void
    {
        AmbassadorRewardRule::factory()->forExistingCustomerBookings()->create();
        $booking = $this->createPaidBookingMadeThroughLinkOf(Ambassador::factory()->create(), $this->createCustomer());
        $earning = $this->earningService()->recordExistingCustomerBookingEarning($booking);

        $this->earningService()->cancelEarningsOfBooking($booking);

        $this->assertSame(AmbassadorEarningStatus::Cancelled, $earning->fresh()->status);
    }

    // ---------------------------------------------------------------- back office page

    public function test_a_user_with_the_permission_can_create_pause_and_delete_a_rule(): void
    {
        $user = $this->createUserWithPermissions([PermissionName::ManageAmbassadorRewardRules->value]);
        $ambassador = Ambassador::factory()->create();

        Livewire::actingAs($user)->test(AmbassadorRewardRuleList::class)
            ->call('openCreateRule')
            ->set('ruleEarningType', AmbassadorEarningType::ExistingCustomerBooking->value)
            ->set('ruleAmount', 50)
            ->set('ruleAmbassadorId', (string) $ambassador->id)
            ->set('ruleIsEnabled', true)
            ->call('saveRule')
            ->assertHasNoErrors();

        $rule = AmbassadorRewardRule::sole();
        $this->assertSame(50, $rule->amount);
        $this->assertSame($ambassador->id, $rule->ambassador_id);
        $this->assertTrue($rule->is_enabled);

        Livewire::actingAs($user)->test(AmbassadorRewardRuleList::class)
            ->call('toggleRule', $rule->id);
        $this->assertFalse($rule->fresh()->is_enabled);

        Livewire::actingAs($user)->test(AmbassadorRewardRuleList::class)
            ->call('askToDeleteRule', $rule->id)
            ->call('confirmDeleteRule');
        $this->assertSame(0, AmbassadorRewardRule::count());
    }

    public function test_the_end_of_a_rule_period_must_come_after_its_start(): void
    {
        $user = $this->createUserWithPermissions([PermissionName::ManageAmbassadorRewardRules->value]);

        Livewire::actingAs($user)->test(AmbassadorRewardRuleList::class)
            ->call('openCreateRule')
            ->set('ruleEarningType', AmbassadorEarningType::Acquisition->value)
            ->set('ruleAmount', 500)
            ->set('ruleStartsAt', '2030-01-10T10:00')
            ->set('ruleEndsAt', '2030-01-09T10:00')
            ->call('saveRule')
            ->assertHasErrors(['ruleEndsAt']);

        $this->assertSame(0, AmbassadorRewardRule::count());
    }

    public function test_a_user_without_the_permission_cannot_change_rules(): void
    {
        $user = $this->createUserWithPermissions([]);
        $rule = AmbassadorRewardRule::factory()->create();

        Livewire::actingAs($user)->test(AmbassadorRewardRuleList::class)
            ->call('toggleRule', $rule->id)
            ->call('openCreateRule')
            ->assertSet('showRuleModal', false);

        $this->assertTrue($rule->fresh()->is_enabled);
    }

    // ---------------------------------------------------------------- embedded for one ambassador

    public function test_embedded_for_one_ambassador_it_only_lists_that_ambassadors_rules(): void
    {
        $user = $this->createUserWithPermissions([PermissionName::ManageAmbassadorRewardRules->value]);
        $ambassador = Ambassador::factory()->create();
        $ownRule = AmbassadorRewardRule::factory()->forAmbassador($ambassador)->create();
        AmbassadorRewardRule::factory()->forAmbassador(Ambassador::factory()->create())->create();
        AmbassadorRewardRule::factory()->create();

        $component = Livewire::actingAs($user)->test(AmbassadorRewardRuleList::class, ['ambassadorId' => $ambassador->id]);

        $this->assertSame([$ownRule->id], $component->instance()->rules()->pluck('id')->all());
    }

    public function test_a_rule_created_when_embedded_belongs_to_that_ambassador_whatever_the_form_says(): void
    {
        $user = $this->createUserWithPermissions([PermissionName::ManageAmbassadorRewardRules->value]);
        $ambassador = Ambassador::factory()->create();

        Livewire::actingAs($user)->test(AmbassadorRewardRuleList::class, ['ambassadorId' => $ambassador->id])
            ->call('openCreateRule')
            ->set('ruleEarningType', AmbassadorEarningType::ExistingCustomerBooking->value)
            ->set('ruleAmount', 50)
            ->set('ruleAmbassadorId', '')
            ->set('ruleIsEnabled', true)
            ->call('saveRule')
            ->assertHasNoErrors();

        $this->assertSame($ambassador->id, AmbassadorRewardRule::sole()->ambassador_id);
    }

    public function test_embedded_for_one_ambassador_it_cannot_touch_the_rules_of_others(): void
    {
        $user = $this->createUserWithPermissions([PermissionName::ManageAmbassadorRewardRules->value]);
        $generalRule = AmbassadorRewardRule::factory()->create();

        $otherAmbassadorId = Ambassador::factory()->create()->id;

        try {
            Livewire::actingAs($user)->test(AmbassadorRewardRuleList::class, ['ambassadorId' => $otherAmbassadorId])
                ->call('toggleRule', $generalRule->id);
        } catch (\Throwable) {
            // a rule outside the ambassador's scope is simply not found
        }
        Livewire::actingAs($user)->test(AmbassadorRewardRuleList::class, ['ambassadorId' => $otherAmbassadorId])
            ->call('askToDeleteRule', $generalRule->id)
            ->call('confirmDeleteRule');

        $this->assertTrue($generalRule->fresh()->is_enabled);
        $this->assertModelExists($generalRule);
    }

    public function test_the_ambassador_list_opens_the_rules_of_one_ambassador(): void
    {
        $user = $this->createUserWithPermissions([PermissionName::ViewAmbassadors->value, PermissionName::ManageAmbassadorRewardRules->value]);
        $ambassador = Ambassador::factory()->create();

        Livewire::actingAs($user)->test(AmbassadorList::class)
            ->call('openRewardRules', $ambassador->id)
            ->assertSet('showRewardRulesModal', true)
            ->assertSet('rewardRulesAmbassadorId', $ambassador->id)
            ->assertSee('Règles de récompense de '.$ambassador->full_name);
    }

    public function test_the_seeder_creates_the_general_sponsorship_rule_once_and_keeps_later_changes(): void
    {
        $this->seed(AmbassadorRewardRuleSeeder::class);
        AmbassadorRewardRule::sole()->update(['amount' => 1234, 'is_enabled' => false]);
        $this->seed(AmbassadorRewardRuleSeeder::class);

        $rule = AmbassadorRewardRule::sole();
        $this->assertSame(AmbassadorEarningType::SponsorshipBonus, $rule->earning_type);
        $this->assertNull($rule->ambassador_id);
        $this->assertSame(1234, $rule->amount);
        $this->assertFalse($rule->is_enabled);
    }

    public function test_the_promotion_reward_types_can_be_given_a_rule(): void
    {
        $user = $this->createUserWithPermissions([PermissionName::ManageAmbassadorRewardRules->value]);

        foreach ([AmbassadorEarningType::AdvertisementShare, AmbassadorEarningType::VideoContent, AmbassadorEarningType::SponsorshipBonus] as $earningType) {
            Livewire::actingAs($user)->test(AmbassadorRewardRuleList::class)
                ->call('openCreateRule')
                ->set('ruleEarningType', $earningType->value)
                ->set('ruleAmount', 100)
                ->set('ruleIsEnabled', true)
                ->call('saveRule')
                ->assertHasNoErrors();

            $this->assertTrue(AmbassadorRewardRule::where('earning_type', $earningType)->exists());
        }
    }

    public function test_the_ambassador_list_records_a_promotion_for_a_user_allowed_to(): void
    {
        $user = $this->createUserWithPermissions([PermissionName::ViewAmbassadors->value, PermissionName::RecordAmbassadorPromotionRewards->value]);
        $ambassador = Ambassador::factory()->create();
        AmbassadorRewardRule::factory()->create(['earning_type' => AmbassadorEarningType::AdvertisementShare, 'amount' => 100]);

        Livewire::actingAs($user)->test(AmbassadorList::class)
            ->call('openPromotionReward', $ambassador->id)
            ->set('promotionType', AmbassadorEarningType::AdvertisementShare->value)
            ->set('promotionProofUrl', 'https://facebook.com/groups/x/posts/1')
            ->call('recordPromotionReward')
            ->assertHasNoErrors();

        $earning = AmbassadorEarning::where('ambassador_id', $ambassador->id)->sole();
        $this->assertSame(100, $earning->amount);
        $this->assertSame($user->id, $earning->recorded_by_user_id);
    }

    public function test_a_user_without_the_permission_cannot_record_a_promotion(): void
    {
        $user = $this->createUserWithPermissions([PermissionName::ViewAmbassadors->value]);
        $ambassador = Ambassador::factory()->create();

        Livewire::actingAs($user)->test(AmbassadorList::class)
            ->call('openPromotionReward', $ambassador->id)
            ->assertSet('showPromotionModal', false);
    }

    public function test_a_promotion_without_a_valid_type_or_link_is_refused(): void
    {
        $user = $this->createUserWithPermissions([PermissionName::ViewAmbassadors->value, PermissionName::RecordAmbassadorPromotionRewards->value]);
        $ambassador = Ambassador::factory()->create();

        Livewire::actingAs($user)->test(AmbassadorList::class)
            ->call('openPromotionReward', $ambassador->id)
            ->set('promotionType', AmbassadorEarningType::Acquisition->value)
            ->set('promotionProofUrl', 'not a url')
            ->call('recordPromotionReward')
            ->assertHasErrors(['promotionType', 'promotionProofUrl']);
    }

    // ---------------------------------------------------------------- earnings dialog

    public function test_selected_pending_earnings_can_be_approved_from_the_earnings_dialog(): void
    {
        $user = $this->createUserWithPermissions([PermissionName::ApproveAmbassadorEarnings->value]);
        $ambassador = Ambassador::factory()->create();
        $selectedEarnings = AmbassadorEarning::factory()->count(2)->create(['ambassador_id' => $ambassador->id]);
        $unselectedEarning = AmbassadorEarning::factory()->create(['ambassador_id' => $ambassador->id]);

        Livewire::actingAs($user)->test(AmbassadorEarningsDialog::class, ['ambassadorId' => $ambassador->id])
            ->set('selectedEarningIds', $selectedEarnings->pluck('id')->all())
            ->set('approvalReason', 'test beta')
            ->call('approveSelectedEarnings')
            ->assertHasNoErrors()
            ->assertSet('selectedEarningIds', []);

        $this->assertSame([AmbassadorEarningStatus::Approved], $selectedEarnings->map(fn ($earning) => $earning->fresh()->status)->unique()->all());
        $this->assertSame(AmbassadorEarningStatus::Pending, $unselectedEarning->fresh()->status);
    }

    public function test_approving_requires_a_selection_and_a_reason(): void
    {
        $user = $this->createUserWithPermissions([PermissionName::ApproveAmbassadorEarnings->value]);
        $ambassador = Ambassador::factory()->create();
        $earning = AmbassadorEarning::factory()->create(['ambassador_id' => $ambassador->id]);

        Livewire::actingAs($user)->test(AmbassadorEarningsDialog::class, ['ambassadorId' => $ambassador->id])
            ->set('selectedEarningIds', [$earning->id])
            ->call('approveSelectedEarnings')
            ->assertHasErrors(['approvalReason']);

        Livewire::actingAs($user)->test(AmbassadorEarningsDialog::class, ['ambassadorId' => $ambassador->id])
            ->set('approvalReason', 'test beta')
            ->call('approveSelectedEarnings')
            ->assertHasErrors(['selectedEarningIds']);

        $this->assertSame(AmbassadorEarningStatus::Pending, $earning->fresh()->status);
    }

    public function test_the_earnings_dialog_cannot_approve_the_earnings_of_another_ambassador(): void
    {
        $user = $this->createUserWithPermissions([PermissionName::ApproveAmbassadorEarnings->value]);
        $foreignEarning = AmbassadorEarning::factory()->create(['ambassador_id' => Ambassador::factory()->create()->id]);

        Livewire::actingAs($user)->test(AmbassadorEarningsDialog::class, ['ambassadorId' => Ambassador::factory()->create()->id])
            ->set('selectedEarningIds', [$foreignEarning->id])
            ->set('approvalReason', 'test beta')
            ->call('approveSelectedEarnings');

        $this->assertSame(AmbassadorEarningStatus::Pending, $foreignEarning->fresh()->status);
    }

    public function test_a_user_without_the_permission_cannot_approve_earnings(): void
    {
        $user = $this->createUserWithPermissions([]);
        $ambassador = Ambassador::factory()->create();
        $earning = AmbassadorEarning::factory()->create(['ambassador_id' => $ambassador->id]);

        Livewire::actingAs($user)->test(AmbassadorEarningsDialog::class, ['ambassadorId' => $ambassador->id])
            ->set('selectedEarningIds', [$earning->id])
            ->set('approvalReason', 'test beta')
            ->call('approveSelectedEarnings');

        $this->assertSame(AmbassadorEarningStatus::Pending, $earning->fresh()->status);
    }

    public function test_the_ambassador_list_opens_the_earnings_dialog(): void
    {
        $user = $this->createUserWithPermissions([PermissionName::ViewAmbassadors->value]);
        $ambassador = Ambassador::factory()->create();

        Livewire::actingAs($user)->test(AmbassadorList::class)
            ->call('openEarnings', $ambassador->id)
            ->assertSet('showEarningsModal', true)
            ->assertSee('Gains de '.$ambassador->full_name);
    }
}
