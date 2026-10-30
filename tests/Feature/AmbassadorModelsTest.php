<?php

namespace Tests\Feature;

use App\Enums\AmbassadorEarningStatus;
use App\Models\Ambassador;
use App\Models\AmbassadorEarning;
use App\Models\AmbassadorPayout;
use App\Models\Booking;
use App\Models\Customer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AmbassadorModelsTest extends TestCase
{
    use DatabaseTransactions;

    private function makeCustomer(): Customer
    {
        return Customer::create([
            'prenom' => 'Test',
            'nom' => 'Client',
            'phone_number' => '77'.random_int(1000000, 9999999),
        ]);
    }

    public function test_code_is_stored_upper_cased_and_trimmed_and_found_regardless_of_case(): void
    {
        $ambassador = Ambassador::factory()->create(['code' => '  awa10 ']);

        $this->assertSame('AWA10', $ambassador->fresh()->code);
        $this->assertTrue(Ambassador::withCode('awa10')->first()->is($ambassador));
    }

    public function test_active_ambassador_with_running_contract_can_acquire_customers(): void
    {
        $ambassador = Ambassador::factory()->create();

        $this->assertTrue($ambassador->canAcquireCustomers());
        $this->assertTrue(Ambassador::acquiringCustomers()->whereKey($ambassador->id)->exists());
    }

    public function test_ambassador_with_expired_contract_cannot_acquire_customers(): void
    {
        $ambassador = Ambassador::factory()->withExpiredContract()->create();

        $this->assertFalse($ambassador->canAcquireCustomers());
        $this->assertFalse(Ambassador::acquiringCustomers()->whereKey($ambassador->id)->exists());
    }

    public function test_contract_ending_today_is_still_running(): void
    {
        $ambassador = Ambassador::factory()->create(['contract_ends_at' => today()]);

        $this->assertTrue($ambassador->canAcquireCustomers());
        $this->assertTrue(Ambassador::acquiringCustomers()->whereKey($ambassador->id)->exists());
    }

    public function test_contract_not_started_yet_is_not_running(): void
    {
        $ambassador = Ambassador::factory()->create(['contract_starts_at' => today()->addDay()]);

        $this->assertFalse($ambassador->canAcquireCustomers());
        $this->assertFalse(Ambassador::acquiringCustomers()->whereKey($ambassador->id)->exists());
    }

    public function test_pending_and_suspended_ambassadors_cannot_acquire_customers(): void
    {
        $pendingAmbassador = Ambassador::factory()->pendingApproval()->create();
        $suspendedAmbassador = Ambassador::factory()->suspended()->create();

        $this->assertFalse($pendingAmbassador->canAcquireCustomers());
        $this->assertFalse($suspendedAmbassador->canAcquireCustomers());
        $this->assertFalse(Ambassador::acquiringCustomers()->whereKey($suspendedAmbassador->id)->exists());
    }

    public function test_sponsor_and_sponsored_ambassadors_are_linked_one_level(): void
    {
        $sponsor = Ambassador::factory()->create();
        $sponsored = Ambassador::factory()->sponsoredBy($sponsor)->pendingApproval()->create();

        $this->assertTrue($sponsored->sponsor->is($sponsor));
        $this->assertTrue($sponsor->sponsoredAmbassadors->first()->is($sponsored));
        $this->assertNull($sponsor->sponsor);
    }

    public function test_payout_phone_number_falls_back_to_ambassador_phone_number(): void
    {
        $ambassadorWithoutWaveNumber = Ambassador::factory()->create(['phone_number' => 771234567]);
        $ambassadorWithWaveNumber = Ambassador::factory()->create(['wave_phone_number' => 781111111]);

        $this->assertSame(771234567, $ambassadorWithoutWaveNumber->payout_phone_number);
        $this->assertSame(781111111, $ambassadorWithWaveNumber->payout_phone_number);
    }

    public function test_customer_and_ambassador_are_linked_both_ways(): void
    {
        $ambassador = Ambassador::factory()->create();
        $customer = $this->makeCustomer();
        $customer->update(['ambassador_id' => $ambassador->id]);

        $this->assertTrue($customer->fresh()->ambassador->is($ambassador));
        $this->assertTrue($ambassador->customers->first()->is($customer));
    }

    public function test_booking_exposes_its_ambassador(): void
    {
        $ambassador = Ambassador::factory()->create();
        $booking = new Booking(['ambassador_id' => $ambassador->id]);

        $this->assertTrue($booking->ambassador->is($ambassador));
    }

    public function test_customer_without_ambassador_has_none(): void
    {
        $this->assertNull($this->makeCustomer()->fresh()->ambassador);
    }

    public function test_earning_idempotency_key_prevents_duplicate_lines(): void
    {
        $ambassador = Ambassador::factory()->create();
        AmbassadorEarning::factory()->for($ambassador)->create(['idempotency_key' => 'acquisition:42']);

        $this->expectException(QueryException::class);

        AmbassadorEarning::factory()->for($ambassador)->create(['idempotency_key' => 'acquisition:42']);
    }

    public function test_not_cancelled_scope_excludes_cancelled_lines(): void
    {
        $ambassador = Ambassador::factory()->create();
        AmbassadorEarning::factory()->for($ambassador)->create(['amount' => 1000]);
        AmbassadorEarning::factory()->for($ambassador)->approved()->create(['amount' => 500]);
        AmbassadorEarning::factory()->for($ambassador)->cancelled()->create(['amount' => 9999]);

        $this->assertSame(1500, (int) $ambassador->earnings()->notCancelled()->sum('amount'));
    }

    public function test_ready_for_approval_scope_only_returns_pending_lines_whose_waiting_period_is_over(): void
    {
        $ambassador = Ambassador::factory()->create();
        $dueEarning = AmbassadorEarning::factory()->for($ambassador)->create(['approvable_at' => now()->subHour()]);
        AmbassadorEarning::factory()->for($ambassador)->create(['approvable_at' => now()->addHour()]);
        AmbassadorEarning::factory()->for($ambassador)->create(['approvable_at' => null]);
        AmbassadorEarning::factory()->for($ambassador)->approved()->create(['approvable_at' => now()->subDay()]);

        $readyEarnings = $ambassador->earnings()->readyForApproval()->get();

        $this->assertCount(1, $readyEarnings);
        $this->assertTrue($readyEarnings->first()->is($dueEarning));
    }

    public function test_payable_scope_excludes_lines_already_attached_to_a_payout(): void
    {
        $ambassador = Ambassador::factory()->create();
        $payout = AmbassadorPayout::factory()->for($ambassador)->create();
        $payableEarning = AmbassadorEarning::factory()->for($ambassador)->approved()->create();
        AmbassadorEarning::factory()->for($ambassador)->approved()->create(['ambassador_payout_id' => $payout->id]);
        AmbassadorEarning::factory()->for($ambassador)->create();

        $payableEarnings = $ambassador->earnings()->payable()->get();

        $this->assertCount(1, $payableEarnings);
        $this->assertTrue($payableEarnings->first()->is($payableEarning));
        $this->assertSame(AmbassadorEarningStatus::Approved, $payableEarnings->first()->status);
    }

    public function test_payout_idempotency_key_is_unique(): void
    {
        $payout = AmbassadorPayout::factory()->create();

        $this->expectException(QueryException::class);

        AmbassadorPayout::factory()->create(['idempotency_key' => $payout->idempotency_key]);
    }
}
