<?php

namespace Tests\Feature;

use App\Data\Profit\ProfitReportDTO;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Caisse;
use App\Services\AccountService;
use App\Services\ProfitService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Profit reporting reads straight off AccountType::nature(): TicketSales and
 * ParcelService count as revenue, Expense counts as an expense, Management
 * counts as neither, and inter-account transfers never count on either side.
 *
 * Assertions on totalRevenue/totalExpenses/profit compare against a baseline
 * taken before each test's own operations, since this suite's DatabaseTransactions
 * setup runs against a real, already-populated database rather than a fresh
 * one — other accounts pre-existing in that database must not affect these
 * tests. Per-account breakdown checks are unaffected by this, since each test
 * creates its own accounts with fresh IDs.
 */
class ProfitServiceTest extends TestCase
{
    use DatabaseTransactions;

    private AccountService $accountService;

    private ProfitService $profitService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accountService = app(AccountService::class);
        $this->profitService = app(ProfitService::class);
    }

    private function makeCaisse(string $name = 'Caisse Test'): Caisse
    {
        return Caisse::create(['name' => $name, 'balance' => 0]);
    }

    private function makeAccount(string $name, AccountType $type): Account
    {
        return Account::create([
            'name' => $name,
            'account_type' => $type,
            'balance' => 0,
            'is_active' => true,
        ]);
    }

    private function amountForAccount(ProfitReportDTO $report, string $side, int $accountId): ?int
    {
        $rows = $side === 'revenue' ? $report->revenueByAccount : $report->expensesByAccount;

        foreach ($rows as $row) {
            if ($row['accountId'] === $accountId) {
                return $row['amount'];
            }
        }

        return null;
    }

    public function test_ticket_sales_and_parcel_service_entrees_count_as_revenue(): void
    {
        $caisse = $this->makeCaisse();
        $ticketSales = $this->makeAccount('Ventes de billets', AccountType::TicketSales);
        $parcelService = $this->makeAccount('Ventes de colis', AccountType::ParcelService);
        $baseline = $this->profitService->computeProfit();

        $this->accountService->processEntreeDeCaisse($caisse, $ticketSales, 100_000, 'Billets du jour');
        $this->accountService->processEntreeDeCaisse($caisse, $parcelService, 40_000, 'Colis du jour');

        $report = $this->profitService->computeProfit();

        $this->assertSame(100_000, $this->amountForAccount($report, 'revenue', $ticketSales->id));
        $this->assertSame(40_000, $this->amountForAccount($report, 'revenue', $parcelService->id));
        $this->assertSame($baseline->totalRevenue + 140_000, $report->totalRevenue);
        $this->assertSame($baseline->totalExpenses, $report->totalExpenses);
        $this->assertSame($baseline->profit + 140_000, $report->profit);
    }

    public function test_sortie_de_caisse_from_an_expense_account_counts_as_an_expense(): void
    {
        $caisse = $this->makeCaisse();
        $ticketSales = $this->makeAccount('Ventes de billets', AccountType::TicketSales);
        $salaires = $this->makeAccount('Salaires', AccountType::Expense);
        $baseline = $this->profitService->computeProfit();

        $this->accountService->processEntreeDeCaisse($caisse, $ticketSales, 200_000, 'Billets du jour');
        $this->accountService->transferBetweenAccounts($ticketSales, $salaires, 80_000, 'Provision salaires');
        $this->accountService->processSortieDeCaisse($caisse, 80_000, 'Paiement salaires', [$salaires->id]);

        $report = $this->profitService->computeProfit();

        $this->assertSame(200_000, $this->amountForAccount($report, 'revenue', $ticketSales->id));
        $this->assertSame(80_000, $this->amountForAccount($report, 'expense', $salaires->id));
        $this->assertSame($baseline->totalRevenue + 200_000, $report->totalRevenue);
        $this->assertSame($baseline->totalExpenses + 80_000, $report->totalExpenses);
        $this->assertSame($baseline->profit + 120_000, $report->profit);
    }

    public function test_management_account_transactions_are_excluded_from_both_sides(): void
    {
        $caisse = $this->makeCaisse();
        $loan = $this->makeAccount('Emprunt', AccountType::Management);
        $baseline = $this->profitService->computeProfit();

        $this->accountService->processEntreeDeCaisse($caisse, $loan, 500_000, 'Emprunt bancaire');

        $report = $this->profitService->computeProfit();

        $this->assertNull($this->amountForAccount($report, 'revenue', $loan->id));
        $this->assertNull($this->amountForAccount($report, 'expense', $loan->id));
        $this->assertSame($baseline->totalRevenue, $report->totalRevenue);
        $this->assertSame($baseline->totalExpenses, $report->totalExpenses);
        $this->assertSame($baseline->profit, $report->profit);
    }

    public function test_transfer_between_accounts_never_counts_as_revenue_or_expense(): void
    {
        $caisse = $this->makeCaisse();
        $ticketSales = $this->makeAccount('Ventes de billets', AccountType::TicketSales);
        $carburant = $this->makeAccount('Carburant', AccountType::Expense);
        $entretien = $this->makeAccount('Entretien', AccountType::Expense);
        $baseline = $this->profitService->computeProfit();

        $this->accountService->processEntreeDeCaisse($caisse, $ticketSales, 300_000, 'Billets du jour');

        // Provisioning "Carburant" from ticket sales, then reallocating part of
        // it to "Entretien" — pure internal moves, no cash has left the caisse.
        $this->accountService->transferBetweenAccounts($ticketSales, $carburant, 100_000, 'Provision carburant');
        $this->accountService->transferBetweenAccounts($carburant, $entretien, 20_000, 'Réallocation');

        $report = $this->profitService->computeProfit();

        $this->assertSame(300_000, $this->amountForAccount($report, 'revenue', $ticketSales->id));
        $this->assertNull($this->amountForAccount($report, 'expense', $carburant->id));
        $this->assertNull($this->amountForAccount($report, 'expense', $entretien->id));
        $this->assertSame($baseline->totalRevenue + 300_000, $report->totalRevenue);
        $this->assertSame($baseline->totalExpenses, $report->totalExpenses);
        $this->assertSame($baseline->profit + 300_000, $report->profit);
    }

    public function test_profit_can_be_scoped_to_a_date_range(): void
    {
        $caisse = $this->makeCaisse();
        $ticketSales = $this->makeAccount('Ventes de billets', AccountType::TicketSales);

        $this->travelTo(Carbon::parse('2026-01-10'));
        $this->accountService->processEntreeDeCaisse($caisse, $ticketSales, 50_000, 'Billets janvier');

        $this->travelTo(Carbon::parse('2026-02-10'));
        $this->accountService->processEntreeDeCaisse($caisse, $ticketSales, 70_000, 'Billets février');

        $this->travelBack();

        $januaryReport = $this->profitService->computeProfit('2026-01-01', '2026-01-31');
        $februaryReport = $this->profitService->computeProfit('2026-02-01', '2026-02-28');

        $this->assertSame(50_000, $this->amountForAccount($januaryReport, 'revenue', $ticketSales->id));
        $this->assertSame(70_000, $this->amountForAccount($februaryReport, 'revenue', $ticketSales->id));
    }
}
