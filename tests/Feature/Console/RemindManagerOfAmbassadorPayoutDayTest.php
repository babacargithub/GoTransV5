<?php

namespace Tests\Feature\Console;

use App\Enums\AmbassadorStatus;
use App\Models\Ambassador;
use App\Models\AmbassadorEarning;
use App\Models\AppParams;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RemindManagerOfAmbassadorPayoutDayTest extends TestCase
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function createAmbassadorWithApprovedEarning(int $earningAmount): Ambassador
    {
        $ambassador = Ambassador::factory()->create();
        AmbassadorEarning::factory()->for($ambassador)->approved()->create(['amount' => $earningAmount]);

        return $ambassador;
    }

    public function test_the_manager_is_reminded_on_the_15th_with_the_count_and_total_of_ambassadors_due(): void
    {
        Carbon::setTestNow('2026-11-15 08:00:00');
        $this->createAmbassadorWithApprovedEarning(self::MINIMUM_PAYOUT_AMOUNT);
        $this->createAmbassadorWithApprovedEarning(self::MINIMUM_PAYOUT_AMOUNT + 1000);
        $this->createAmbassadorWithApprovedEarning(self::MINIMUM_PAYOUT_AMOUNT - 1);
        $this->mock(NotificationService::class)
            ->shouldReceive('notifyManagerOfAmbassadorPayoutDay')
            ->once()
            ->with(2, 11000);

        $this->artisan('ambassador:remind-manager-of-payout-day')->assertSuccessful();
    }

    public function test_the_manager_is_reminded_on_the_last_day_of_the_month(): void
    {
        Carbon::setTestNow('2026-02-28 08:00:00');
        $this->createAmbassadorWithApprovedEarning(self::MINIMUM_PAYOUT_AMOUNT);
        $this->mock(NotificationService::class)
            ->shouldReceive('notifyManagerOfAmbassadorPayoutDay')
            ->once()
            ->with(1, self::MINIMUM_PAYOUT_AMOUNT);

        $this->artisan('ambassador:remind-manager-of-payout-day')->assertSuccessful();
    }

    public function test_a_suspended_ambassador_is_counted_in_the_reminder(): void
    {
        Carbon::setTestNow('2026-11-15 08:00:00');
        $this->createAmbassadorWithApprovedEarning(self::MINIMUM_PAYOUT_AMOUNT)->update(['status' => AmbassadorStatus::Suspended]);
        $this->mock(NotificationService::class)
            ->shouldReceive('notifyManagerOfAmbassadorPayoutDay')
            ->once()
            ->with(1, self::MINIMUM_PAYOUT_AMOUNT);

        $this->artisan('ambassador:remind-manager-of-payout-day')->assertSuccessful();
    }

    public function test_no_reminder_is_sent_on_an_ordinary_day(): void
    {
        Carbon::setTestNow('2026-11-16 08:00:00');
        $this->createAmbassadorWithApprovedEarning(self::MINIMUM_PAYOUT_AMOUNT);
        $this->mock(NotificationService::class)->shouldNotReceive('notifyManagerOfAmbassadorPayoutDay');

        $this->artisan('ambassador:remind-manager-of-payout-day')->assertSuccessful();
    }

    public function test_no_reminder_is_sent_on_a_payout_day_when_nobody_is_due(): void
    {
        Carbon::setTestNow('2026-11-30 08:00:00');
        $this->createAmbassadorWithApprovedEarning(self::MINIMUM_PAYOUT_AMOUNT - 1);
        $this->mock(NotificationService::class)->shouldNotReceive('notifyManagerOfAmbassadorPayoutDay');

        $this->artisan('ambassador:remind-manager-of-payout-day')->assertSuccessful();
    }

    public function test_an_ambassador_with_an_invalid_earning_is_left_out_and_does_not_stop_the_reminder(): void
    {
        Carbon::setTestNow('2026-11-15 08:00:00');
        $this->createAmbassadorWithApprovedEarning(self::MINIMUM_PAYOUT_AMOUNT);
        $this->createAmbassadorWithApprovedEarning(0);
        $this->mock(NotificationService::class)
            ->shouldReceive('notifyManagerOfAmbassadorPayoutDay')
            ->once()
            ->with(1, self::MINIMUM_PAYOUT_AMOUNT);

        $this->artisan('ambassador:remind-manager-of-payout-day')->assertSuccessful();
    }
}
