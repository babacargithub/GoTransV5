<?php

namespace Tests\Feature;

use App\Enums\AccountTransactionCategory;
use App\Enums\AccountTransactionType;
use App\Enums\AmbassadorPayoutStatus;
use App\Enums\CaisseCode;
use App\Exceptions\InsufficientAccountBalanceException;
use App\Jobs\RecordAmbassadorCommissionReserveJob;
use App\Jobs\RecordAmbassadorPayoutInCaisseJob;
use App\Jobs\ReleaseAmbassadorCommissionReserveJob;
use App\Manager\BookingManager;
use App\Models\AccountTransaction;
use App\Models\Ambassador;
use App\Models\AmbassadorEarning;
use App\Models\AmbassadorPayout;
use App\Models\Booking;
use App\Models\Caisse;
use App\Models\Customer;
use App\Models\Depart;
use App\Models\Destination;
use App\Models\PointDep;
use App\Models\Trajet;
use App\Services\AccountService;
use App\Services\AmbassadorCommissionAccountingService;
use App\Services\AmbassadorEarningService;
use App\Services\ProfitService;
use App\Services\WaveService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The "Commissions ambassadeurs" account: a commission is set aside from TicketSales as soon as it is earned,
 * given back when cancelled, and only becomes an expense (with a Wave caisse withdrawal) when its payout settles.
 */
class AmbassadorCommissionAccountingTest extends TestCase
{
    use DatabaseTransactions;

    private const TICKET_SALES_FUNDING = 100_000;

    private AccountService $accountService;

    private AmbassadorCommissionAccountingService $accounting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->accountService = app(AccountService::class);
        $this->accounting = app(AmbassadorCommissionAccountingService::class);
    }

    private function fundTicketSales(int $amount = self::TICKET_SALES_FUNDING): void
    {
        $this->accountService->depositToCaisseAndCreditAccount(
            caisse: Caisse::findByCode(CaisseCode::TicketCash),
            account: $this->accountService->getOrCreateTicketSalesAccount(),
            amount: $amount,
            caisseLabel: 'Test',
            accountLabel: 'Test',
            categoryOverride: AccountTransactionCategory::Revenue,
        );
    }

    private function ticketSalesBalance(): int
    {
        return $this->accountService->getOrCreateTicketSalesAccount()->fresh()->balance;
    }

    private function commissionsBalance(): int
    {
        return $this->accountService->getOrCreateAmbassadorCommissionsAccount()->fresh()->balance;
    }

    private function createDepartWithBus(): Depart
    {
        $trajet = Trajet::create(['name' => 'Caravane '.uniqid(), 'departure_city' => 'DAKAR', 'arrival_city' => 'SAINT-LOUIS']);
        $depart = Depart::create([
            'name' => 'DEPART COMMISSION '.uniqid(),
            'date' => now()->addDays(3),
            'trajet_id' => $trajet->id,
            'visibilite' => Depart::VISIBILITE_ALL_CUSTOMERS,
            'closed' => false,
            'locked' => false,
            'canceled' => false,
        ]);
        $depart->buses()->create(['name' => 'Bus Test', 'nombre_place' => 50, 'ticket_price' => 4000, 'gp_ticket_price' => 6000, 'closed' => false]);
        PointDep::create(['name' => 'Gare '.uniqid(), 'trajet_id' => $trajet->id, 'disabled' => false]);
        Destination::create(['name' => 'Dakar centre', 'trajet_id' => $trajet->id]);

        return $depart;
    }

    private function createBookingOfAcquiredCustomer(Ambassador $ambassador, Depart $depart): Booking
    {
        $customer = Customer::create([
            'prenom' => 'Awa',
            'nom' => 'Diop',
            'phone_number' => fake()->unique()->numerify('77#######'),
            'ambassador_id' => $ambassador->id,
            'last_active' => now(),
        ]);

        return Booking::create([
            'customer_id' => $customer->id,
            'depart_id' => $depart->id,
            'bus_id' => $depart->buses()->firstOrFail()->id,
            'point_dep_id' => PointDep::where('trajet_id', $depart->trajet_id)->firstOrFail()->id,
            'destination_id' => Destination::where('trajet_id', $depart->trajet_id)->firstOrFail()->id,
            'paye' => true,
            'group_id' => (int) BookingManager::generateBookingGroupId(),
        ]);
    }

    private function transactionsOf(string $referenceType, int $referenceId): Collection
    {
        return AccountTransaction::query()->where('reference_type', $referenceType)->where('reference_id', $referenceId)->get();
    }

    public function test_a_commission_is_moved_from_ticket_sales_to_the_commissions_account_as_an_internal_transfer(): void
    {
        $this->fundTicketSales();
        $earning = AmbassadorEarning::factory()->create(['amount' => 500]);
        $ticketSalesBefore = $this->ticketSalesBalance();

        $this->assertTrue($this->accounting->reserveEarning($earning));

        $this->assertSame($ticketSalesBefore - 500, $this->ticketSalesBalance());
        $this->assertSame(500, $this->commissionsBalance());
        $reserveTransactions = $this->transactionsOf(AmbassadorCommissionAccountingService::RESERVE_REFERENCE_TYPE, $earning->id);
        $this->assertCount(2, $reserveTransactions);
        $this->assertTrue($reserveTransactions->every(fn (AccountTransaction $transaction): bool => $transaction->category === AccountTransactionCategory::Internal));
    }

    public function test_reserving_the_same_commission_twice_records_it_once(): void
    {
        $this->fundTicketSales();
        $earning = AmbassadorEarning::factory()->create(['amount' => 500]);

        $this->accounting->reserveEarning($earning);
        $this->assertFalse($this->accounting->reserveEarning($earning));

        $this->assertSame(500, $this->commissionsBalance());
    }

    public function test_a_cancelled_commission_is_never_reserved(): void
    {
        $this->fundTicketSales();
        $earning = AmbassadorEarning::factory()->cancelled()->create(['amount' => 500]);

        $this->assertFalse($this->accounting->reserveEarning($earning));

        $this->assertCount(0, $this->transactionsOf(AmbassadorCommissionAccountingService::RESERVE_REFERENCE_TYPE, $earning->id));
    }

    public function test_a_commission_cannot_be_reserved_when_ticket_sales_cannot_cover_it(): void
    {
        $ticketSalesBalance = $this->ticketSalesBalance();
        $earning = AmbassadorEarning::factory()->create(['amount' => $ticketSalesBalance + 1]);

        $this->expectException(InsufficientAccountBalanceException::class);

        $this->accounting->reserveEarning($earning);
    }

    public function test_cancelling_a_reserved_commission_gives_the_money_back_to_ticket_sales_once(): void
    {
        $this->fundTicketSales();
        $earning = AmbassadorEarning::factory()->create(['amount' => 500]);
        $this->accounting->reserveEarning($earning);
        $ticketSalesAfterReserve = $this->ticketSalesBalance();
        $earning->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        $this->assertTrue($this->accounting->releaseEarning($earning));
        $this->assertFalse($this->accounting->releaseEarning($earning));

        $this->assertSame($ticketSalesAfterReserve + 500, $this->ticketSalesBalance());
        $this->assertSame(0, $this->commissionsBalance());
        $releaseTransactions = $this->transactionsOf(AmbassadorCommissionAccountingService::RELEASE_REFERENCE_TYPE, $earning->id);
        $this->assertTrue($releaseTransactions->every(fn (AccountTransaction $transaction): bool => $transaction->category === AccountTransactionCategory::Internal));
    }

    public function test_a_commission_cancelled_before_it_was_reserved_has_nothing_to_release(): void
    {
        $earning = AmbassadorEarning::factory()->cancelled()->create(['amount' => 500]);

        $this->assertFalse($this->accounting->releaseEarning($earning));
    }

    public function test_a_settled_payout_leaves_the_wave_caisse_and_is_the_only_expense_of_the_commissions(): void
    {
        $this->fundTicketSales();
        $ambassador = Ambassador::factory()->create();
        $paidEarning = AmbassadorEarning::factory()->for($ambassador)->approved()->create(['amount' => 1500]);
        $payout = AmbassadorPayout::factory()->for($ambassador)->succeeded()->create(['amount' => 1500]);
        $paidEarning->update(['ambassador_payout_id' => $payout->id, 'status' => 'paid']);
        $profitService = app(ProfitService::class);
        $expensesBeforeReserve = $profitService->computeProfit()->totalExpenses;
        $waveCaisseBefore = Caisse::findByCode(CaisseCode::Wave)->fresh()->balance;
        $this->accounting->reserveEarning($paidEarning);

        $this->assertSame($expensesBeforeReserve, $profitService->computeProfit()->totalExpenses, 'reserving is not an expense');

        $this->assertTrue($this->accounting->recordPayout($payout));
        $this->assertFalse($this->accounting->recordPayout($payout));

        $this->assertSame($expensesBeforeReserve + 1500, $profitService->computeProfit()->totalExpenses);
        $this->assertSame(0, $this->commissionsBalance());
        $this->assertSame($waveCaisseBefore - 1500, Caisse::findByCode(CaisseCode::Wave)->fresh()->balance);
        $payoutTransaction = $this->transactionsOf(AmbassadorCommissionAccountingService::PAYOUT_REFERENCE_TYPE, $payout->id)->sole();
        $this->assertSame(AccountTransactionCategory::Expense, $payoutTransaction->category);
        $this->assertSame(AccountTransactionType::Debit, $payoutTransaction->transaction_type);
    }

    public function test_a_payout_reserves_its_commissions_first_when_they_were_not_set_aside_yet(): void
    {
        $this->fundTicketSales();
        $ambassador = Ambassador::factory()->create();
        $earning = AmbassadorEarning::factory()->for($ambassador)->approved()->create(['amount' => 1500]);
        $payout = AmbassadorPayout::factory()->for($ambassador)->succeeded()->create(['amount' => 1500]);
        $earning->update(['ambassador_payout_id' => $payout->id, 'status' => 'paid']);

        $this->accounting->recordPayout($payout);

        $this->assertCount(2, $this->transactionsOf(AmbassadorCommissionAccountingService::RESERVE_REFERENCE_TYPE, $earning->id));
        $this->assertSame(0, $this->commissionsBalance());
    }

    public function test_an_unsettled_payout_is_not_recorded(): void
    {
        $payout = AmbassadorPayout::factory()->create(['status' => AmbassadorPayoutStatus::Pending]);

        $this->assertFalse($this->accounting->recordPayout($payout));
        $this->assertCount(0, $this->transactionsOf(AmbassadorCommissionAccountingService::PAYOUT_REFERENCE_TYPE, $payout->id));
    }

    public function test_earning_a_commission_queues_its_reservation_and_cancelling_it_queues_its_release(): void
    {
        Queue::fake();
        $depart = $this->createDepartWithBus();
        $booking = $this->createBookingOfAcquiredCustomer(Ambassador::factory()->create(), $depart);
        $earningService = app(AmbassadorEarningService::class);

        $earning = $earningService->recordAcquisitionEarning($booking);
        $earningService->recordAcquisitionEarning($booking);
        Queue::assertPushed(RecordAmbassadorCommissionReserveJob::class, 1);

        $earningService->cancelEarningsOfBooking($booking);
        Queue::assertPushed(ReleaseAmbassadorCommissionReserveJob::class, 1);
        $this->assertSame('cancelled', $earning->fresh()->status->value);
    }

    public function test_a_failing_accounting_never_prevents_the_commission_from_being_earned(): void
    {
        $this->partialMock(AmbassadorCommissionAccountingService::class, function ($accountingMock): void {
            $accountingMock->shouldReceive('reserveEarning')->andThrow(new InsufficientAccountBalanceException('empty'));
        });
        $booking = $this->createBookingOfAcquiredCustomer(Ambassador::factory()->create(), $this->createDepartWithBus());

        $earning = app(AmbassadorEarningService::class)->recordAcquisitionEarning($booking);

        $this->assertNotNull($earning);
        $this->assertTrue($earning->exists);
    }

    public function test_the_commissions_of_a_depart_count_pending_and_approved_ones_but_not_cancelled_ones_nor_other_departs(): void
    {
        Queue::fake();
        $depart = $this->createDepartWithBus();
        $otherDepart = $this->createDepartWithBus();
        $ambassador = Ambassador::factory()->create();
        $earningService = app(AmbassadorEarningService::class);
        $pendingBooking = $this->createBookingOfAcquiredCustomer($ambassador, $depart);
        $approvedBooking = $this->createBookingOfAcquiredCustomer($ambassador, $depart);
        $cancelledBooking = $this->createBookingOfAcquiredCustomer($ambassador, $depart);
        $otherDepartBooking = $this->createBookingOfAcquiredCustomer($ambassador, $otherDepart);
        $earningService->recordAcquisitionEarning($pendingBooking);
        $earningService->recordAcquisitionEarning($approvedBooking)->update(['status' => 'approved']);
        $earningService->recordAcquisitionEarning($cancelledBooking);
        $earningService->cancelEarningsOfBooking($cancelledBooking);
        $earningService->recordAcquisitionEarning($otherDepartBooking);

        $this->assertSame(1000, $earningService->totalCommissionsOfDepart($depart));
        $this->assertSame(500, $earningService->totalCommissionsOfDepart($otherDepart));
    }

    public function test_the_backfill_command_sets_aside_missing_commissions_and_records_settled_payouts_once(): void
    {
        $this->fundTicketSales();
        $ambassador = Ambassador::factory()->create();
        $earning = AmbassadorEarning::factory()->for($ambassador)->approved()->create(['amount' => 700]);
        $paidEarning = AmbassadorEarning::factory()->for($ambassador)->approved()->create(['amount' => 1500]);
        $payout = AmbassadorPayout::factory()->for($ambassador)->succeeded()->create(['amount' => 1500]);
        $paidEarning->update(['ambassador_payout_id' => $payout->id, 'status' => 'paid']);

        $this->artisan('ambassador:record-missing-commission-entries')->assertSuccessful();
        $this->artisan('ambassador:record-missing-commission-entries')->assertSuccessful();

        $this->assertCount(2, $this->transactionsOf(AmbassadorCommissionAccountingService::RESERVE_REFERENCE_TYPE, $earning->id));
        $this->assertCount(2, $this->transactionsOf(AmbassadorCommissionAccountingService::RESERVE_REFERENCE_TYPE, $paidEarning->id));
        $this->assertCount(1, $this->transactionsOf(AmbassadorCommissionAccountingService::PAYOUT_REFERENCE_TYPE, $payout->id));
    }

    public function test_a_settled_payout_queues_its_caisse_recording(): void
    {
        Queue::fake();
        $payout = AmbassadorPayout::factory()->create(['amount' => 1500]);

        $paidPayout = app(WaveService::class)->pay($payout);

        $this->assertSame(AmbassadorPayoutStatus::Success, $paidPayout->status);
        Queue::assertPushed(RecordAmbassadorPayoutInCaisseJob::class, 1);
    }
}
