<?php

namespace Tests\Feature;

use App\Enums\AccountTransactionCategory;
use App\Enums\CaisseCode;
use App\Jobs\RecordTicketPaymentInCaisse;
use App\Manager\TicketManager;
use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Caisse;
use App\Models\Ticket;
use App\Services\AccountService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * AccountService::reverseTicketSaleForRefund() — the ledger-side reversal of
 * a ticket sale, wired to fire on every booking refund (see BookingController
 *
 * @refundTicket). Verifies it withdraws the ORIGINAL recorded amount (not a
 * recomputed one) from the caisse the sale actually landed in, debits the
 * same account it credited as Expense, and is idempotent.
 */
class AccountServiceTicketRefundTest extends TestCase
{
    use DatabaseTransactions;

    private AccountService $accountService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accountService = app(AccountService::class);
    }

    private function makeTicket(int $price, string $paymentMethod): Ticket
    {
        $ticket = new Ticket;
        $ticket->number = random_int(100_000_000, 999_999_999);
        $ticket->price = $price;
        $ticket->soldBy = 'system';
        $ticket->soldAt = now();
        $ticket->used = true;
        $ticket->payment_method = $paymentMethod;
        $ticket->save();

        return $ticket;
    }

    public function test_reversing_a_cash_ticket_sale_withdraws_the_full_price_and_debits_the_credited_account_as_expense(): void
    {
        $ticket = $this->makeTicket(3550, 'cash');
        RecordTicketPaymentInCaisse::dispatchSync($ticket->id);

        $ticketCashCaisse = Caisse::findByCode(CaisseCode::TicketCash);
        $ticketSalesAccount = $this->accountService->getOrCreateTicketSalesAccount();
        $caisseBalanceAfterSale = $ticketCashCaisse->fresh()->balance;
        $accountBalanceAfterSale = $ticketSalesAccount->fresh()->balance;

        $reversal = $this->accountService->reverseTicketSaleForRefund($ticket);

        $this->assertNotNull($reversal);
        $this->assertSame(AccountTransactionCategory::Expense, $reversal->category);
        $this->assertSame('TICKET_REFUND', $reversal->reference_type);
        $this->assertSame($ticket->id, $reversal->reference_id);
        $this->assertSame(3550, $reversal->amount);
        $this->assertSame($caisseBalanceAfterSale - 3550, $ticketCashCaisse->fresh()->balance);
        $this->assertSame($accountBalanceAfterSale - 3550, $ticketSalesAccount->fresh()->balance);
        $this->assertTrue($this->accountService->isGlobalInvariantSatisfied());
    }

    public function test_reversing_a_wave_ticket_sale_withdraws_the_net_amount_actually_recorded_not_the_full_price(): void
    {
        // 4000 base price inflated by TicketManager's 1% Wave surcharge = 4040;
        // the original sale only credited the NET amount (round(4040 / 1.01) = 4000).
        $ticket = $this->makeTicket(4040, 'wave');
        RecordTicketPaymentInCaisse::dispatchSync($ticket->id);

        $waveCaisse = Caisse::findByCode(CaisseCode::Wave);
        $netAmount = (int) round(4040 / (1 + TicketManager::WAVE_FEES));
        $this->assertSame($netAmount, $waveCaisse->fresh()->balance);

        $reversal = $this->accountService->reverseTicketSaleForRefund($ticket);

        $this->assertSame($netAmount, $reversal->amount);
        $this->assertSame(0, $waveCaisse->fresh()->balance);
    }

    public function test_reversing_the_same_ticket_twice_is_a_no_op_the_second_time(): void
    {
        $ticket = $this->makeTicket(3550, 'cash');
        RecordTicketPaymentInCaisse::dispatchSync($ticket->id);

        $firstReversal = $this->accountService->reverseTicketSaleForRefund($ticket);
        $secondReversal = $this->accountService->reverseTicketSaleForRefund($ticket);

        $this->assertNotNull($firstReversal);
        $this->assertNull($secondReversal);
        $this->assertSame(1, AccountTransaction::where('reference_type', 'TICKET_REFUND')->where('reference_id', $ticket->id)->count());
    }

    public function test_reversing_a_ticket_that_was_never_recorded_as_a_sale_is_a_no_op(): void
    {
        // "card" is not a recognized payment method, so RecordTicketPaymentInCaisse
        // never deposited/credited anything for it in the first place.
        $ticket = $this->makeTicket(3550, 'card');

        $reversal = $this->accountService->reverseTicketSaleForRefund($ticket);

        $this->assertNull($reversal);
        $this->assertSame(0, AccountTransaction::where('reference_id', $ticket->id)->count());
    }

    public function test_reversal_debits_the_exact_account_the_original_sale_credited_even_if_its_type_changed_since(): void
    {
        $ticket = $this->makeTicket(3550, 'cash');
        RecordTicketPaymentInCaisse::dispatchSync($ticket->id);

        // Simulate the account being reconfigured after the sale.
        Account::where('account_type', 'TICKET_SALES')->update(['account_type' => 'MANAGEMENT']);

        $reversal = $this->accountService->reverseTicketSaleForRefund($ticket);

        $this->assertNotNull($reversal);
        $this->assertSame(AccountTransactionCategory::Expense, $reversal->category);
    }
}
