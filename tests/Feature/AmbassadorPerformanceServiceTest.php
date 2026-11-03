<?php

namespace Tests\Feature;

use App\Data\Ambassador\AmbassadorBalanceDTO;
use App\Enums\AmbassadorEarningStatus;
use App\Models\Ambassador;
use App\Models\AmbassadorEarning;
use App\Models\AmbassadorPayout;
use App\Models\Customer;
use App\Services\AmbassadorPerformanceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AmbassadorPerformanceServiceTest extends TestCase
{
    use DatabaseTransactions;

    private function createEarning(Ambassador $ambassador, AmbassadorEarningStatus $status, int $amount, array $attributes = []): AmbassadorEarning
    {
        return AmbassadorEarning::factory()->create(array_merge([
            'ambassador_id' => $ambassador->id,
            'status' => $status,
            'amount' => $amount,
        ], $attributes));
    }

    public function test_an_ambassador_without_earnings_has_a_zero_balance_everywhere(): void
    {
        $ambassador = Ambassador::factory()->create();

        $balance = app(AmbassadorPerformanceService::class)->getAccountBalance($ambassador);

        $this->assertInstanceOf(AmbassadorBalanceDTO::class, $balance);
        $this->assertSame(0, $balance->totalBalance);
        $this->assertSame(0, $balance->availableBalance);
        $this->assertSame(0, $balance->pendingBalance);
        $this->assertSame(0, $balance->paidBalance);
    }

    public function test_available_is_the_sum_of_approved_earnings(): void
    {
        $ambassador = Ambassador::factory()->create();
        $this->createEarning($ambassador, AmbassadorEarningStatus::Approved, 500);
        $this->createEarning($ambassador, AmbassadorEarningStatus::Approved, 700);

        $balance = app(AmbassadorPerformanceService::class)->getAccountBalance($ambassador);

        $this->assertSame(1200, $balance->availableBalance);
    }

    public function test_pending_is_the_sum_of_pending_earnings(): void
    {
        $ambassador = Ambassador::factory()->create();
        $this->createEarning($ambassador, AmbassadorEarningStatus::Pending, 500);
        $this->createEarning($ambassador, AmbassadorEarningStatus::Pending, 300);

        $balance = app(AmbassadorPerformanceService::class)->getAccountBalance($ambassador);

        $this->assertSame(800, $balance->pendingBalance);
    }

    public function test_paid_is_the_sum_of_paid_earnings(): void
    {
        $ambassador = Ambassador::factory()->create();
        $this->createEarning($ambassador, AmbassadorEarningStatus::Paid, 2000, ['paid_at' => now()]);
        $this->createEarning($ambassador, AmbassadorEarningStatus::Paid, 500, ['paid_at' => now()]);

        $balance = app(AmbassadorPerformanceService::class)->getAccountBalance($ambassador);

        $this->assertSame(2500, $balance->paidBalance);
    }

    public function test_total_is_available_plus_pending_and_excludes_what_was_already_paid(): void
    {
        $ambassador = Ambassador::factory()->create();
        $this->createEarning($ambassador, AmbassadorEarningStatus::Approved, 1000);
        $this->createEarning($ambassador, AmbassadorEarningStatus::Pending, 400);
        $this->createEarning($ambassador, AmbassadorEarningStatus::Paid, 9000, ['paid_at' => now()]);

        $balance = app(AmbassadorPerformanceService::class)->getAccountBalance($ambassador);

        $this->assertSame(1400, $balance->totalBalance);
        $this->assertSame(1000, $balance->availableBalance);
        $this->assertSame(400, $balance->pendingBalance);
        $this->assertSame(9000, $balance->paidBalance);
    }

    public function test_cancelled_earnings_are_counted_in_no_figure(): void
    {
        $ambassador = Ambassador::factory()->create();
        $this->createEarning($ambassador, AmbassadorEarningStatus::Cancelled, 5000, ['cancelled_at' => now()]);

        $balance = app(AmbassadorPerformanceService::class)->getAccountBalance($ambassador);

        $this->assertSame(0, $balance->totalBalance);
        $this->assertSame(0, $balance->availableBalance);
        $this->assertSame(0, $balance->pendingBalance);
        $this->assertSame(0, $balance->paidBalance);
    }

    public function test_approved_earnings_already_attached_to_an_unfinished_payout_still_count_as_available(): void
    {
        $ambassador = Ambassador::factory()->create();
        $payout = AmbassadorPayout::factory()->create(['ambassador_id' => $ambassador->id]);
        $this->createEarning($ambassador, AmbassadorEarningStatus::Approved, 1500, ['ambassador_payout_id' => $payout->id]);

        $balance = app(AmbassadorPerformanceService::class)->getAccountBalance($ambassador);

        $this->assertSame(1500, $balance->availableBalance);
        $this->assertSame(1500, $balance->totalBalance);
    }

    public function test_the_balance_only_includes_the_earnings_of_the_given_ambassador(): void
    {
        $ambassador = Ambassador::factory()->create();
        $otherAmbassador = Ambassador::factory()->create();
        $this->createEarning($ambassador, AmbassadorEarningStatus::Approved, 500);
        $this->createEarning($otherAmbassador, AmbassadorEarningStatus::Approved, 7777);
        $this->createEarning($otherAmbassador, AmbassadorEarningStatus::Pending, 8888);
        $this->createEarning($otherAmbassador, AmbassadorEarningStatus::Paid, 9999, ['paid_at' => now()]);

        $balance = app(AmbassadorPerformanceService::class)->getAccountBalance($ambassador);

        $this->assertSame(500, $balance->totalBalance);
        $this->assertSame(500, $balance->availableBalance);
        $this->assertSame(0, $balance->pendingBalance);
        $this->assertSame(0, $balance->paidBalance);
    }

    public function test_balance_figures_are_integers_even_with_large_sums(): void
    {
        $ambassador = Ambassador::factory()->create();
        foreach (range(1, 20) as $unused) {
            $this->createEarning($ambassador, AmbassadorEarningStatus::Approved, 500_000);
        }

        $balance = app(AmbassadorPerformanceService::class)->getAccountBalance($ambassador);

        $this->assertSame(10_000_000, $balance->availableBalance);
        $this->assertSame(10_000_000, $balance->totalBalance);
    }

    public function test_acquired_customers_count_only_counts_customers_of_that_ambassador(): void
    {
        $ambassador = Ambassador::factory()->create();
        $otherAmbassador = Ambassador::factory()->create();
        foreach ([$ambassador, $ambassador, $otherAmbassador, null] as $owner) {
            Customer::create([
                'prenom' => 'Test',
                'nom' => 'Client',
                'phone_number' => fake()->unique()->numerify('77#######'),
                'ambassador_id' => $owner?->id,
            ]);
        }

        $this->assertSame(2, app(AmbassadorPerformanceService::class)->countAcquiredCustomers($ambassador));
        $this->assertSame(0, app(AmbassadorPerformanceService::class)->countAcquiredCustomers(Ambassador::factory()->create()));
    }

    public function test_sponsored_ambassadors_count_only_counts_direct_sponsored_ambassadors(): void
    {
        $sponsor = Ambassador::factory()->create();
        $sponsored = Ambassador::factory()->sponsoredBy($sponsor)->create();
        Ambassador::factory()->sponsoredBy($sponsor)->create();
        Ambassador::factory()->sponsoredBy($sponsored)->create();
        Ambassador::factory()->create();

        $this->assertSame(2, app(AmbassadorPerformanceService::class)->countSponsoredAmbassadors($sponsor));
        $this->assertSame(1, app(AmbassadorPerformanceService::class)->countSponsoredAmbassadors($sponsored));
    }
}
