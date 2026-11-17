<?php

namespace Tests\Feature\BackOffice;

use App\Enums\AmbassadorEarningStatus;
use App\Enums\AmbassadorPayoutStatus;
use App\Enums\AmbassadorStatus;
use App\Enums\PermissionName;
use App\Livewire\BackOffice\AmbassadorPayoutList;
use App\Models\Ambassador;
use App\Models\AmbassadorEarning;
use App\Models\AmbassadorPayout;
use App\Models\AppParams;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class AmbassadorPayoutListTest extends TestCase
{
    use DatabaseTransactions;

    private const MINIMUM_PAYOUT_AMOUNT = 5000;

    protected function setUp(): void
    {
        parent::setUp();

        $appParams = AppParams::query()->first() ?? new AppParams(['data' => []]);
        $appParams->data = collect($appParams->data ?? [])
            ->merge(['ambassador_minimum_payout_amount' => self::MINIMUM_PAYOUT_AMOUNT])
            ->all();
        $appParams->save();
    }

    private function createPayerUser(): User
    {
        return $this->createUserWithPermissions([PermissionName::ViewAmbassadors->value, PermissionName::PayAmbassadors->value]);
    }

    private function createAmbassadorWithApprovedEarning(int $earningAmount, array $ambassadorAttributes = []): Ambassador
    {
        $ambassador = Ambassador::factory()->create($ambassadorAttributes + ['wave_phone_number' => '771234567']);
        AmbassadorEarning::factory()->for($ambassador)->approved()->create(['amount' => $earningAmount]);

        return $ambassador;
    }

    public function test_a_user_without_the_view_permission_is_forbidden(): void
    {
        $this->actingAs($this->createUserWithPermissions([]))
            ->get(route('back-office.ambassador-payouts.index'))
            ->assertForbidden();
    }

    public function test_the_page_lists_payable_ambassadors_and_payouts_with_their_failure_reason(): void
    {
        $payableAmbassador = $this->createAmbassadorWithApprovedEarning(7500, ['first_name' => 'Awa', 'last_name' => 'Payable']);
        AmbassadorPayout::factory()->create([
            'status' => AmbassadorPayoutStatus::Failed,
            'amount' => 3000,
            'failure_reason' => 'Wave HTTP 500 : boom',
        ]);

        Livewire::actingAs($this->createPayerUser())->test(AmbassadorPayoutList::class)
            ->assertSee('Awa Payable')
            ->assertSee('7 500 FCFA')
            ->assertSee('Wave HTTP 500 : boom')
            ->assertSee('Échoué');

        $this->assertNotNull($payableAmbassador);
    }

    public function test_paying_an_ambassador_creates_a_settled_payout_requested_by_the_user(): void
    {
        $ambassador = $this->createAmbassadorWithApprovedEarning(self::MINIMUM_PAYOUT_AMOUNT);
        $payerUser = $this->createPayerUser();

        Livewire::actingAs($payerUser)->test(AmbassadorPayoutList::class)
            ->call('payAmbassador', $ambassador->id);

        $payout = AmbassadorPayout::query()->where('ambassador_id', $ambassador->id)->sole();
        $this->assertSame(self::MINIMUM_PAYOUT_AMOUNT, $payout->amount);
        $this->assertSame($payerUser->id, $payout->requested_by_user_id);
        $this->assertSame(AmbassadorPayoutStatus::Success, $payout->status);
        $this->assertSame(AmbassadorEarningStatus::Paid, $ambassador->earnings()->sole()->status);
    }

    public function test_paying_twice_never_pays_the_same_earnings_twice(): void
    {
        $ambassador = $this->createAmbassadorWithApprovedEarning(self::MINIMUM_PAYOUT_AMOUNT);

        Livewire::actingAs($this->createPayerUser())->test(AmbassadorPayoutList::class)
            ->call('payAmbassador', $ambassador->id)
            ->call('payAmbassador', $ambassador->id);

        $this->assertSame(1, AmbassadorPayout::query()->where('ambassador_id', $ambassador->id)->count());
    }

    public function test_a_user_who_can_only_view_cannot_pay_or_retry(): void
    {
        $ambassador = $this->createAmbassadorWithApprovedEarning(self::MINIMUM_PAYOUT_AMOUNT);
        $failedPayout = AmbassadorPayout::factory()->create(['status' => AmbassadorPayoutStatus::Failed, 'attempts_count' => 1]);
        $viewerUser = $this->createUserWithPermissions([PermissionName::ViewAmbassadors->value]);

        Livewire::actingAs($viewerUser)->test(AmbassadorPayoutList::class)
            ->call('payAmbassador', $ambassador->id)
            ->call('retryPayout', $failedPayout->id);

        $this->assertSame(0, AmbassadorPayout::query()->where('ambassador_id', $ambassador->id)->count());
        $this->assertSame(AmbassadorPayoutStatus::Failed, $failedPayout->fresh()->status);
        $this->assertSame(1, $failedPayout->fresh()->attempts_count);
    }

    public function test_an_ambassador_below_the_minimum_or_not_in_a_payable_status_cannot_be_paid(): void
    {
        $belowMinimumAmbassador = $this->createAmbassadorWithApprovedEarning(self::MINIMUM_PAYOUT_AMOUNT - 1);
        $rejectedAmbassador = $this->createAmbassadorWithApprovedEarning(self::MINIMUM_PAYOUT_AMOUNT * 2);
        $rejectedAmbassador->update(['status' => AmbassadorStatus::Rejected]);

        Livewire::actingAs($this->createPayerUser())->test(AmbassadorPayoutList::class)
            ->call('payAmbassador', $belowMinimumAmbassador->id)
            ->call('payAmbassador', $rejectedAmbassador->id);

        $this->assertSame(0, AmbassadorPayout::query()->whereIn('ambassador_id', [$belowMinimumAmbassador->id, $rejectedAmbassador->id])->count());
    }

    public function test_a_suspended_ambassador_is_listed_and_still_receives_the_money_already_earned(): void
    {
        $suspendedAmbassador = $this->createAmbassadorWithApprovedEarning(self::MINIMUM_PAYOUT_AMOUNT * 2);
        $suspendedAmbassador->update(['status' => AmbassadorStatus::Suspended]);

        Livewire::actingAs($this->createPayerUser())->test(AmbassadorPayoutList::class)
            ->assertSee($suspendedAmbassador->full_name)
            ->call('payAmbassador', $suspendedAmbassador->id);

        $this->assertSame(1, AmbassadorPayout::query()->where('ambassador_id', $suspendedAmbassador->id)->count());
    }

    public function test_a_failed_payout_with_attempts_left_can_be_retried_with_the_same_idempotency_key(): void
    {
        $ambassador = Ambassador::factory()->create();
        $failedPayout = AmbassadorPayout::factory()->for($ambassador)->create([
            'status' => AmbassadorPayoutStatus::Failed,
            'failure_reason' => 'Wave injoignable',
            'attempts_count' => 1,
        ]);
        AmbassadorEarning::factory()->for($ambassador)->approved()->create(['ambassador_payout_id' => $failedPayout->id]);
        $idempotencyKey = $failedPayout->idempotency_key;

        Livewire::actingAs($this->createPayerUser())->test(AmbassadorPayoutList::class)
            ->call('retryPayout', $failedPayout->id);

        $failedPayout->refresh();
        $this->assertSame(AmbassadorPayoutStatus::Success, $failedPayout->status);
        $this->assertSame(2, $failedPayout->attempts_count);
        $this->assertSame($idempotencyKey, $failedPayout->idempotency_key);
        $this->assertSame(AmbassadorEarningStatus::Paid, $ambassador->earnings()->sole()->status);
    }

    public function test_payouts_that_cannot_be_sent_are_never_sent_again(): void
    {
        Http::fake();
        $succeededPayout = AmbassadorPayout::factory()->succeeded()->create(['attempts_count' => 1]);
        $exhaustedPayout = AmbassadorPayout::factory()->create([
            'status' => AmbassadorPayoutStatus::Failed,
            'attempts_count' => AmbassadorPayout::MAX_ATTEMPTS,
        ]);
        $acceptedByWavePayout = AmbassadorPayout::factory()->create(['provider_payout_id' => 'wave_abc', 'attempts_count' => 1]);

        Livewire::actingAs($this->createPayerUser())->test(AmbassadorPayoutList::class)
            ->call('retryPayout', $succeededPayout->id)
            ->call('retryPayout', $exhaustedPayout->id)
            ->call('retryPayout', $acceptedByWavePayout->id);

        $this->assertSame(1, $succeededPayout->fresh()->attempts_count);
        $this->assertSame(AmbassadorPayout::MAX_ATTEMPTS, $exhaustedPayout->fresh()->attempts_count);
        $this->assertSame(1, $acceptedByWavePayout->fresh()->attempts_count);
        Http::assertNothingSent();
    }

    public function test_the_history_can_be_filtered_by_status(): void
    {
        AmbassadorPayout::factory()->succeeded()->create(['amount' => 1111]);
        AmbassadorPayout::factory()->create(['status' => AmbassadorPayoutStatus::Failed, 'amount' => 2222]);

        Livewire::actingAs($this->createPayerUser())->test(AmbassadorPayoutList::class)
            ->set('statusFilter', AmbassadorPayoutStatus::Failed->value)
            ->assertSee('2 222 FCFA')
            ->assertDontSee('1 111 FCFA');
    }
}
