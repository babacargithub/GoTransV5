<?php

namespace Tests\Feature\Console;

use App\Services\WaveService;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Tests\TestCase;

class RetryUnsettledAmbassadorPayoutsTest extends TestCase
{
    public function test_the_command_reports_how_many_payouts_were_retried(): void
    {
        $this->partialMock(WaveService::class, function ($waveServiceMock): void {
            $waveServiceMock->shouldReceive('retryUnsettledPayouts')->once()->andReturn(3);
        });

        $this->artisan('ambassador:retry-unsettled-payouts')
            ->expectsOutput('3 payout(s) retried.')
            ->assertSuccessful();
    }

    public function test_no_payout_command_is_scheduled_because_payouts_are_triggered_manually(): void
    {
        $this->app->make(Kernel::class)->bootstrap();

        $scheduledPayoutCommands = collect($this->app->make(Schedule::class)->events())
            ->filter(fn (Event $event): bool => str_contains($event->command, 'ambassador:retry-unsettled-payouts'));

        $this->assertCount(0, $scheduledPayoutCommands);
    }
}
