<?php

namespace Tests\Feature;

use App\Enums\AmbassadorEarningStatus;
use App\Enums\AmbassadorPayoutStatus;
use App\Models\Ambassador;
use App\Models\AmbassadorEarning;
use App\Models\AmbassadorPayout;
use App\Services\EarningPayoutService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class EarningPayoutServiceTest extends TestCase
{
    use DatabaseTransactions;

    private function payoutService(): EarningPayoutService
    {
        return app(EarningPayoutService::class);
    }

    private function createPendingPayoutWithEarnings(int ...$earningAmounts): AmbassadorPayout
    {
        $ambassador = Ambassador::factory()->create(['wave_phone_number' => '771234567']);
        foreach ($earningAmounts as $earningAmount) {
            AmbassadorEarning::factory()->for($ambassador)->approved()->create(['amount' => $earningAmount]);
        }

        return $this->payoutService()->createPayoutForPayableEarnings($ambassador);
    }

    public function test_payable_earnings_are_the_approved_earnings_of_the_ambassador_without_payout(): void
    {
        $ambassador = Ambassador::factory()->create();
        $payout = AmbassadorPayout::factory()->for($ambassador)->create();
        $payableEarning = AmbassadorEarning::factory()->for($ambassador)->approved()->create();
        AmbassadorEarning::factory()->for($ambassador)->create();
        AmbassadorEarning::factory()->for($ambassador)->cancelled()->create();
        AmbassadorEarning::factory()->for($ambassador)->approved()->create(['ambassador_payout_id' => $payout->id]);
        AmbassadorEarning::factory()->approved()->create();

        $payableEarnings = $this->payoutService()->payableEarnings($ambassador);

        $this->assertSame([$payableEarning->id], $payableEarnings->modelKeys());
    }

    public function test_payable_earnings_can_be_locked_inside_a_transaction(): void
    {
        $ambassador = Ambassador::factory()->create();
        $payableEarning = AmbassadorEarning::factory()->for($ambassador)->approved()->create();

        $payableEarnings = DB::transaction(fn () => $this->payoutService()->payableEarnings($ambassador, lockForUpdate: true));

        $this->assertSame([$payableEarning->id], $payableEarnings->modelKeys());
    }

    public function test_payable_earnings_are_empty_for_an_ambassador_without_earnings(): void
    {
        $this->assertTrue($this->payoutService()->payableEarnings(Ambassador::factory()->create())->isEmpty());
    }

    public function test_payable_amount_is_the_sum_of_the_ambassador_approved_earnings(): void
    {
        $ambassador = Ambassador::factory()->create();
        foreach ([500, 2000, 1] as $earningAmount) {
            AmbassadorEarning::factory()->for($ambassador)->approved()->create(['amount' => $earningAmount]);
        }

        $this->assertSame(2501, $this->payoutService()->calculatePayableAmount($ambassador));
    }

    public function test_payable_amount_is_zero_for_an_ambassador_without_earnings(): void
    {
        $this->assertSame(0, $this->payoutService()->calculatePayableAmount(Ambassador::factory()->create()));
    }

    public function test_payable_amount_ignores_earnings_that_are_pending_paid_or_cancelled(): void
    {
        $ambassador = Ambassador::factory()->create();
        AmbassadorEarning::factory()->for($ambassador)->approved()->create(['amount' => 500]);
        AmbassadorEarning::factory()->for($ambassador)->create(['amount' => 9000]);
        AmbassadorEarning::factory()->for($ambassador)->cancelled()->create(['amount' => 9000]);
        AmbassadorEarning::factory()->for($ambassador)->approved()->create([
            'amount' => 9000,
            'status' => AmbassadorEarningStatus::Paid,
        ]);

        $this->assertSame(500, $this->payoutService()->calculatePayableAmount($ambassador));
    }

    /**
     * @return array<string, array{0: AmbassadorPayoutStatus}>
     */
    public static function payoutStatusProvider(): array
    {
        return [
            'pending payout' => [AmbassadorPayoutStatus::Pending],
            'failed payout' => [AmbassadorPayoutStatus::Failed],
            'succeeded payout' => [AmbassadorPayoutStatus::Success],
        ];
    }

    #[DataProvider('payoutStatusProvider')]
    public function test_payable_amount_ignores_earnings_attached_to_a_payout_whatever_its_status(AmbassadorPayoutStatus $payoutStatus): void
    {
        $ambassador = Ambassador::factory()->create();
        $payout = AmbassadorPayout::factory()->for($ambassador)->create(['status' => $payoutStatus]);
        AmbassadorEarning::factory()->for($ambassador)->approved()->create(['amount' => 500]);
        AmbassadorEarning::factory()->for($ambassador)->approved()->create([
            'amount' => 9000,
            'ambassador_payout_id' => $payout->id,
        ]);

        $this->assertSame(500, $this->payoutService()->calculatePayableAmount($ambassador));
    }

    public function test_payable_amount_ignores_the_earnings_of_other_ambassadors(): void
    {
        $ambassador = Ambassador::factory()->create();
        AmbassadorEarning::factory()->for($ambassador)->approved()->create(['amount' => 500]);
        AmbassadorEarning::factory()->approved()->create(['amount' => 9000]);

        $this->assertSame(500, $this->payoutService()->calculatePayableAmount($ambassador));
    }

    public function test_payable_amount_is_an_integer_even_for_large_totals(): void
    {
        $ambassador = Ambassador::factory()->create();
        AmbassadorEarning::factory()->for($ambassador)->approved()->create(['amount' => 1_000_000_000]);
        AmbassadorEarning::factory()->for($ambassador)->approved()->create(['amount' => 2_000_000_000]);

        $this->assertSame(3_000_000_000, $this->payoutService()->calculatePayableAmount($ambassador));
    }

    public function test_payable_amount_refuses_an_owed_earning_with_a_zero_amount(): void
    {
        $ambassador = Ambassador::factory()->create();
        AmbassadorEarning::factory()->for($ambassador)->approved()->create(['amount' => 500]);
        AmbassadorEarning::factory()->for($ambassador)->approved()->create(['amount' => 0]);

        $this->expectException(InvalidArgumentException::class);

        $this->payoutService()->calculatePayableAmount($ambassador);
    }

    public function test_earnings_of_a_failed_payout_are_not_counted_again_in_a_new_payout(): void
    {
        $failedPayout = $this->createPendingPayoutWithEarnings(500);
        $failedPayout->update(['status' => AmbassadorPayoutStatus::Failed]);
        AmbassadorEarning::factory()->for($failedPayout->ambassador)->approved()->create(['amount' => 300]);

        $newPayout = $this->payoutService()->createPayoutForPayableEarnings($failedPayout->ambassador);

        $this->assertSame(300, $newPayout->amount);
    }

    public function test_payout_amount_matches_the_calculated_payable_amount(): void
    {
        $ambassador = Ambassador::factory()->create(['wave_phone_number' => '771234567']);
        AmbassadorEarning::factory()->for($ambassador)->approved()->create(['amount' => 300]);
        AmbassadorEarning::factory()->for($ambassador)->approved()->create(['amount' => 450]);
        $payableAmount = $this->payoutService()->calculatePayableAmount($ambassador);

        $payout = $this->payoutService()->createPayoutForPayableEarnings($ambassador);

        $this->assertSame(750, $payableAmount);
        $this->assertSame($payableAmount, $payout->amount);
    }

    public function test_approved_earnings_are_grouped_under_one_pending_payout_without_calling_wave(): void
    {
        Http::fake();
        $ambassador = Ambassador::factory()->create(['wave_phone_number' => '771234567']);
        AmbassadorEarning::factory()->for($ambassador)->approved()->create(['amount' => 500]);
        AmbassadorEarning::factory()->for($ambassador)->approved()->create(['amount' => 2000]);
        AmbassadorEarning::factory()->for($ambassador)->create(['amount' => 900]);
        AmbassadorEarning::factory()->for($ambassador)->cancelled()->create(['amount' => 700]);

        $payout = $this->payoutService()->createPayoutForPayableEarnings($ambassador, 42);

        $this->assertSame(2500, $payout->amount);
        $this->assertSame(AmbassadorPayoutStatus::Pending, $payout->status);
        $this->assertSame(771234567, (int) $payout->wave_phone_number);
        $this->assertSame('Commissions Ambassadeur Globe One', $payout->payment_reason);
        $this->assertSame(42, $payout->requested_by_user_id);
        $this->assertNotEmpty($payout->idempotency_key);
        $this->assertSame(2, $payout->earnings()->count());
        Http::assertNothingSent();
    }

    public function test_no_payout_is_kept_when_not_every_earning_could_be_attached_to_it(): void
    {
        $ambassador = Ambassador::factory()->create(['wave_phone_number' => '771234567']);
        AmbassadorEarning::factory()->for($ambassador)->approved()->create(['amount' => 500]);
        $this->app['events']->listen(
            'eloquent.created: '.AmbassadorPayout::class,
            fn () => AmbassadorEarning::query()->where('ambassador_id', $ambassador->id)->delete(),
        );

        try {
            $this->payoutService()->createPayoutForPayableEarnings($ambassador);
            $this->fail('A RuntimeException was expected.');
        } catch (RuntimeException) {
            $this->assertSame(0, AmbassadorPayout::where('ambassador_id', $ambassador->id)->count());
        }
    }

    public function test_a_custom_payment_reason_is_stored_on_the_payout(): void
    {
        $ambassador = Ambassador::factory()->create(['wave_phone_number' => '771234567']);
        AmbassadorEarning::factory()->for($ambassador)->approved()->create();

        $payout = $this->payoutService()->createPayoutForPayableEarnings($ambassador, null, '  Prime de novembre  ');

        $this->assertSame('Prime de novembre', $payout->payment_reason);
    }

    /**
     * @return array<string, array{0: ?string}>
     */
    public static function missingPaymentReasonProvider(): array
    {
        return ['null' => [null], 'empty' => [''], 'blank' => ['   ']];
    }

    #[DataProvider('missingPaymentReasonProvider')]
    public function test_a_missing_payment_reason_falls_back_to_the_default_one(?string $missingPaymentReason): void
    {
        $ambassador = Ambassador::factory()->create(['wave_phone_number' => '771234567']);
        AmbassadorEarning::factory()->for($ambassador)->approved()->create();

        $payout = $this->payoutService()->createPayoutForPayableEarnings($ambassador, null, $missingPaymentReason);

        $this->assertSame(EarningPayoutService::PAYMENT_REASON, $payout->payment_reason);
    }

    public function test_a_too_long_payment_reason_is_refused_and_nothing_is_grouped(): void
    {
        $ambassador = Ambassador::factory()->create(['wave_phone_number' => '771234567']);
        $earning = AmbassadorEarning::factory()->for($ambassador)->approved()->create();
        $tooLongPaymentReason = str_repeat('a', EarningPayoutService::MAX_PAYMENT_REASON_LENGTH + 1);

        try {
            $this->payoutService()->createPayoutForPayableEarnings($ambassador, null, $tooLongPaymentReason);
            $this->fail('An InvalidArgumentException was expected.');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, AmbassadorPayout::where('ambassador_id', $ambassador->id)->count());
            $this->assertNull($earning->fresh()->ambassador_payout_id);
        }
    }

    public function test_earnings_already_grouped_are_never_grouped_again(): void
    {
        $payout = $this->createPendingPayoutWithEarnings(500);

        $secondPayout = $this->payoutService()->createPayoutForPayableEarnings($payout->ambassador);

        $this->assertNull($secondPayout);
        $this->assertSame(1, AmbassadorPayout::where('ambassador_id', $payout->ambassador_id)->count());
    }
}
