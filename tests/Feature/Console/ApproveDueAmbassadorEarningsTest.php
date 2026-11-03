<?php

namespace Tests\Feature\Console;

use App\Enums\AmbassadorEarningStatus;
use App\Models\Ambassador;
use App\Models\AmbassadorEarning;
use App\Services\AmbassadorEarningService;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Tests\TestCase;

class ApproveDueAmbassadorEarningsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_the_command_approves_due_pending_earnings_and_reports_how_many(): void
    {
        $ambassador = Ambassador::factory()->create();
        $dueEarning = AmbassadorEarning::factory()->create(['ambassador_id' => $ambassador->id, 'approvable_at' => now()->subHour()]);
        $notDueEarning = AmbassadorEarning::factory()->create(['ambassador_id' => $ambassador->id, 'approvable_at' => now()->addHour()]);
        $expectedApprovedCount = AmbassadorEarning::readyForApproval()->count();

        $this->artisan('ambassador:approve-due-earnings')
            ->expectsOutput("{$expectedApprovedCount} earning(s) approved.")
            ->assertSuccessful();

        $this->assertSame(AmbassadorEarningStatus::Approved, $dueEarning->fresh()->status);
        $this->assertSame(AmbassadorEarningStatus::Pending, $notDueEarning->fresh()->status);
    }

    public function test_running_the_command_twice_approves_nothing_the_second_time(): void
    {
        AmbassadorEarning::factory()->create(['ambassador_id' => Ambassador::factory()->create()->id, 'approvable_at' => now()->subHour()]);
        $this->artisan('ambassador:approve-due-earnings')->assertSuccessful();

        $this->artisan('ambassador:approve-due-earnings')
            ->expectsOutput('0 earning(s) approved.')
            ->assertSuccessful();
    }

    public function test_a_second_successful_run_leaves_already_approved_earnings_untouched(): void
    {
        $firstRunMoment = now()->startOfSecond();
        $this->travelTo($firstRunMoment);
        $dueEarning = AmbassadorEarning::factory()->create(['ambassador_id' => Ambassador::factory()->create()->id, 'approvable_at' => now()->subHour()]);
        $this->artisan('ambassador:approve-due-earnings')->assertSuccessful();

        $this->travelTo($firstRunMoment->copy()->addHours(2));
        $this->artisan('ambassador:approve-due-earnings')->expectsOutput('0 earning(s) approved.')->assertSuccessful();

        $dueEarning->refresh();
        $this->assertSame(AmbassadorEarningStatus::Approved, $dueEarning->status);
        $this->assertTrue($firstRunMoment->equalTo($dueEarning->approved_at));
    }

    public function test_the_command_never_touches_earnings_that_are_already_paid_or_cancelled(): void
    {
        $ambassador = Ambassador::factory()->create();
        $paidEarning = AmbassadorEarning::factory()->create([
            'ambassador_id' => $ambassador->id,
            'status' => AmbassadorEarningStatus::Paid,
            'approvable_at' => now()->subDay(),
            'paid_at' => now()->subHour(),
        ]);
        $cancelledEarning = AmbassadorEarning::factory()->cancelled()->create(['ambassador_id' => $ambassador->id, 'approvable_at' => now()->subDay()]);

        $this->artisan('ambassador:approve-due-earnings')->assertSuccessful();

        $this->assertSame(AmbassadorEarningStatus::Paid, $paidEarning->fresh()->status);
        $this->assertSame(AmbassadorEarningStatus::Cancelled, $cancelledEarning->fresh()->status);
    }

    public function test_a_failing_run_fails_the_command_and_leaves_due_earnings_pending(): void
    {
        $dueEarning = AmbassadorEarning::factory()->create(['ambassador_id' => Ambassador::factory()->create()->id, 'approvable_at' => now()->subHour()]);
        $this->partialMock(AmbassadorEarningService::class, function ($serviceMock): void {
            $serviceMock->shouldReceive('approveDueEarnings')->once()->andThrow(new RuntimeException('database unavailable'));
        });

        try {
            Artisan::call('ambassador:approve-due-earnings');
            $this->fail('The command should have propagated the failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('database unavailable', $exception->getMessage());
        }

        $dueEarning->refresh();
        $this->assertSame(AmbassadorEarningStatus::Pending, $dueEarning->status);
        $this->assertNull($dueEarning->approved_at);
    }

    public function test_the_next_run_after_a_failed_run_approves_the_earnings_that_were_missed(): void
    {
        $ambassador = Ambassador::factory()->create();
        $dueEarning = AmbassadorEarning::factory()->create(['ambassador_id' => $ambassador->id, 'approvable_at' => now()->subHour()]);
        $alsoDueEarning = AmbassadorEarning::factory()->create(['ambassador_id' => $ambassador->id, 'approvable_at' => now()->subMinute()]);
        $this->partialMock(AmbassadorEarningService::class, function ($serviceMock): void {
            $serviceMock->shouldReceive('approveDueEarnings')->once()->andThrow(new RuntimeException('database unavailable'));
        });
        try {
            Artisan::call('ambassador:approve-due-earnings');
        } catch (RuntimeException) {
            // the failed run is the precondition of this test
        }
        $this->app->forgetInstance(AmbassadorEarningService::class);
        $expectedApprovedCount = AmbassadorEarning::readyForApproval()->count();

        $this->artisan('ambassador:approve-due-earnings')
            ->expectsOutput("{$expectedApprovedCount} earning(s) approved.")
            ->assertSuccessful();

        $this->assertSame(AmbassadorEarningStatus::Approved, $dueEarning->fresh()->status);
        $this->assertSame(AmbassadorEarningStatus::Approved, $alsoDueEarning->fresh()->status);
    }

    public function test_the_command_is_scheduled_hourly(): void
    {
        $this->app->make(Kernel::class)->bootstrap();

        $scheduledEvent = collect($this->app->make(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains($event->command, 'ambassador:approve-due-earnings'));

        $this->assertNotNull($scheduledEvent);
        $this->assertSame('0 * * * *', $scheduledEvent->expression);
    }
}
