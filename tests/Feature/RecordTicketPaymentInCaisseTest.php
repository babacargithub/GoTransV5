<?php

namespace Tests\Feature;

use App\Enums\AccountTransactionCategory;
use App\Enums\AccountType;
use App\Enums\CaisseCode;
use App\Jobs\RecordTicketPaymentInCaisse;
use App\Manager\TicketManager;
use App\Models\AccountTransaction;
use App\Models\Caisse;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AccountService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The caisse/account routing performed by the queued RecordTicketPaymentInCaisse
 * job: which caisse a ticket sale lands in, and how much — including Wave's
 * fee deduction, which Orange Money and cash do not have.
 */
class RecordTicketPaymentInCaisseTest extends TestCase
{
    use DatabaseTransactions;

    private function makeTicket(int $price, ?string $paymentMethod): Ticket
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

    public function test_cash_ticket_sale_deposits_the_full_price_into_the_ticket_cash_caisse(): void
    {
        $ticket = $this->makeTicket(3550, 'cash');

        RecordTicketPaymentInCaisse::dispatchSync($ticket->id);

        $ticketCashCaisse = Caisse::findByCode(CaisseCode::TicketCash);
        $this->assertSame(3550, $ticketCashCaisse->fresh()->balance);
    }

    public function test_wave_ticket_sale_deposits_the_net_amount_after_waves_cut_into_the_wave_caisse(): void
    {
        // 4000 base price inflated by TicketManager's 1% Wave surcharge = 4040.
        $ticket = $this->makeTicket(4040, 'wave');

        RecordTicketPaymentInCaisse::dispatchSync($ticket->id);

        $waveCaisse = Caisse::findByCode(CaisseCode::Wave);
        $this->assertSame(4000, $waveCaisse->fresh()->balance);
        $this->assertSame(round(4040 / (1 + TicketManager::WAVE_FEES)), (float) $waveCaisse->fresh()->balance);
    }

    public function test_om_ticket_sale_deposits_the_full_price_into_the_om_caisse_with_no_fee_deduction(): void
    {
        $ticket = $this->makeTicket(4040, 'om');

        RecordTicketPaymentInCaisse::dispatchSync($ticket->id);

        $omCaisse = Caisse::findByCode(CaisseCode::OrangeMoney);
        $this->assertSame(4040, $omCaisse->fresh()->balance);
    }

    public function test_payment_method_matching_is_case_insensitive(): void
    {
        $ticket = $this->makeTicket(3550, 'WAVE');

        RecordTicketPaymentInCaisse::dispatchSync($ticket->id);

        $waveCaisse = Caisse::findByCode(CaisseCode::Wave);
        $this->assertGreaterThan(0, $waveCaisse->fresh()->balance);
    }

    public function test_unrecognized_payment_method_is_skipped_without_error(): void
    {
        $ticket = $this->makeTicket(3550, 'card');

        RecordTicketPaymentInCaisse::dispatchSync($ticket->id);

        $this->assertSame(0, (int) AccountTransaction::where('reference_id', $ticket->id)->count());
    }

    public function test_missing_caisse_configuration_is_skipped_without_error(): void
    {
        Caisse::findByCode(CaisseCode::TicketCash)->delete();
        $ticket = $this->makeTicket(3550, 'cash');

        RecordTicketPaymentInCaisse::dispatchSync($ticket->id);

        $this->assertSame(0, (int) AccountTransaction::where('reference_id', $ticket->id)->count());
    }

    public function test_ticket_sale_is_always_persisted_as_revenue_regardless_of_the_ticket_sales_account_type(): void
    {
        // The TicketSales account is normally nature=Income, which already
        // resolves to Revenue via AccountService::determineCategory() — but
        // this job must force Revenue explicitly, so it stays correct even
        // if that account's type is ever misconfigured.
        app(AccountService::class)->getOrCreateTicketSalesAccount()
            ->update(['account_type' => AccountType::Management]);

        $ticket = $this->makeTicket(3550, 'cash');

        RecordTicketPaymentInCaisse::dispatchSync($ticket->id);

        $accountTransaction = AccountTransaction::where('reference_id', $ticket->id)->first();
        $this->assertSame(AccountTransactionCategory::Revenue, $accountTransaction->category);
    }

    public function test_job_is_idempotent_and_does_not_double_deposit_on_retry(): void
    {
        $ticket = $this->makeTicket(3550, 'cash');

        RecordTicketPaymentInCaisse::dispatchSync($ticket->id);
        RecordTicketPaymentInCaisse::dispatchSync($ticket->id);

        $ticketCashCaisse = Caisse::findByCode(CaisseCode::TicketCash);
        $this->assertSame(3550, $ticketCashCaisse->fresh()->balance);
        $this->assertSame(1, AccountTransaction::where('reference_type', 'TICKET_SALE')->where('reference_id', $ticket->id)->count());
    }

    public function test_manual_recovery_uses_the_given_reference_type_label_and_user_id(): void
    {
        $agent = User::factory()->create();
        $ticket = $this->makeTicket(4040, 'om');

        RecordTicketPaymentInCaisse::dispatchSync(
            $ticket->id,
            'MANUAL_TICKET_PAYMENT',
            'Paiement manuel (preuve fournie)',
            $agent->id,
        );

        $accountTransaction = AccountTransaction::where('reference_id', $ticket->id)->first();
        $this->assertSame('MANUAL_TICKET_PAYMENT', $accountTransaction->reference_type);
        $this->assertSame('Paiement manuel (preuve fournie)', $accountTransaction->label);
        $this->assertSame($agent->id, $accountTransaction->user_id);

        $caisseTransaction = Caisse::findByCode(CaisseCode::OrangeMoney)->transactions()->first();
        $this->assertSame($agent->id, $caisseTransaction->user_id);
    }
}
