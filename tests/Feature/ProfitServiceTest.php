<?php

namespace Tests\Feature;

use App\Data\Profit\ProfitReportDTO;
use App\Enums\AccountTransactionCategory;
use App\Enums\AccountTransactionType;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Caisse;
use App\Models\Customer;
use App\Models\Depart;
use App\Models\ExpenseType;
use App\Models\Seat;
use App\Models\Ticket;
use App\Models\Trajet;
use App\Services\AccountService;
use App\Services\ProfitService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    public function test_ticket_sale_ledger_entries_do_not_count_as_revenue_because_cost_reports_carry_them(): void
    {
        $caisse = $this->makeCaisse();
        $ticketSales = $this->makeAccount('Ventes de billets', AccountType::TicketSales);
        $baseline = $this->profitService->computeProfit();

        $pastTicketId = $this->createTicketBookedOnDepartAt(now()->subDay());
        $this->accountService->depositToCaisseAndCreditAccount(
            $caisse, $ticketSales, 30_000, 'Vente', 'Vente', 'TICKET_SALE', $pastTicketId,
        );

        $report = $this->profitService->computeProfit();

        $this->assertNull($this->amountForAccount($report, 'revenue', $ticketSales->id));
        $this->assertSame($baseline->totalRevenue, $report->totalRevenue);
    }

    public function test_expenses_are_broken_down_by_type_with_unclassified_expenses_last(): void
    {
        $caisse = $this->makeCaisse();
        $account = $this->makeAccount('Compte charges (test types)', AccountType::Management);
        $this->accountService->processEntreeDeCaisse($caisse, $account, 100_000, 'Fonds');

        $airtimeType = ExpenseType::factory()->create(['name' => 'Airtime (test)']);
        $fuelType = ExpenseType::factory()->create(['name' => 'Carburant (test)']);
        $baseline = $this->profitService->computeProfit();
        $baselineUnclassifiedAmount = collect($baseline->expensesByType)->firstWhere('expenseTypeId', null)['amount'] ?? 0;

        $this->accountService->processSortieDeCaisse($caisse, 10_000, 'Airtime', [$account->id], expenseTypeId: $airtimeType->id, categoryOverride: AccountTransactionCategory::Expense);
        $this->accountService->processSortieDeCaisse($caisse, 30_000, 'Gasoil', [$account->id], expenseTypeId: $fuelType->id, categoryOverride: AccountTransactionCategory::Expense);
        $this->accountService->processSortieDeCaisse($caisse, 5_000, 'Divers', [$account->id], categoryOverride: AccountTransactionCategory::Expense);

        $expensesByType = collect($this->profitService->computeProfit()->expensesByType);

        $this->assertSame(10_000, $expensesByType->firstWhere('expenseTypeId', $airtimeType->id)['amount']);
        $this->assertSame(30_000, $expensesByType->firstWhere('expenseTypeId', $fuelType->id)['amount']);
        $this->assertSame($baselineUnclassifiedAmount + 5_000, $expensesByType->firstWhere('expenseTypeId', null)['amount']);
        $this->assertNull($expensesByType->last()['expenseTypeId']);
    }

    private function createTicketBookedOnDepartAt(Carbon $departDate): int
    {
        $departId = DB::table('departs')->insertGetId(['date' => $departDate, 'name' => 'Test', 'closed' => false, 'locked' => false, 'created_at' => now(), 'updated_at' => now()]);
        $ticketId = DB::table('tickets')->insertGetId(['number' => fake()->unique()->numberBetween(900000000, 999999999), 'price' => 10000, 'soldBy' => 'system', 'soldAt' => now(), 'used' => false, 'created_at' => now(), 'updated_at' => now()]);
        Schema::withoutForeignKeyConstraints(fn () => DB::table('bookings')->insert(['depart_id' => $departId, 'ticket_id' => $ticketId, 'customer_id' => 0, 'point_dep_id' => 0, 'destination_id' => 0, 'paye' => true, 'created_at' => now(), 'updated_at' => now()]));

        return $ticketId;
    }

    public function test_a_debit_tagged_as_revenue_lowers_revenue_instead_of_raising_it(): void
    {
        $caisse = $this->makeCaisse();
        $account = $this->makeAccount('Compte Revenu Erroné', AccountType::Management);
        $baselineRevenue = $this->profitService->computeProfit()->totalRevenue;

        $this->accountService->processEntreeDeCaisse($caisse, $account, 100_000, 'Encaissement', categoryOverride: AccountTransactionCategory::Revenue);
        $this->accountService->processSortieDeCaisse($caisse, 30_000, 'Sortie erronée', [$account->id], categoryOverride: AccountTransactionCategory::Revenue);

        $this->assertSame($baselineRevenue + 70_000, $this->profitService->computeProfit()->totalRevenue);
    }

    public function test_revenue_is_the_cost_reported_ticket_sales_plus_non_ticket_revenue_entries(): void
    {
        $trajet = Trajet::query()->has('pointDeps')->has('destinations')->firstOrFail();
        $depart = Depart::create(['name' => 'DEPART REVENU '.uniqid(), 'date' => '2019-06-10 08:00:00', 'trajet_id' => $trajet->id, 'closed' => false, 'locked' => false, 'canceled' => false]);
        $bus = $depart->buses()->create(['name' => 'Bus Revenu', 'nombre_place' => 57, 'ticket_price' => 3550, 'gp_ticket_price' => 6000, 'closed' => false]);
        $ticket = new Ticket;
        $ticket->forceFill(['number' => random_int(1_000_000, 9_999_999_999), 'price' => 50_000, 'payment_method' => 'wave', 'used' => true, 'soldBy' => 'agence test', 'soldAt' => now(), 'expiryDate' => now()->addDays(30)])->save();
        $bus->bookings()->create([
            'customer_id' => Customer::create(['prenom' => 'awa', 'nom' => 'ndiaye', 'phone_number' => 770000000 + random_int(1, 9999999)])->id,
            'depart_id' => $depart->id,
            'point_dep_id' => $trajet->pointDeps()->firstOrFail()->id,
            'destination_id' => $trajet->destinations()->firstOrFail()->id,
            'seat_id' => $bus->seats()->create(['seat_id' => Seat::query()->inRandomOrder()->firstOrFail()->id, 'booked' => true, 'price' => 50_000])->id,
            'ticket_id' => $ticket->id,
            'paye' => true,
        ]);
        $depart->costReport()->create();

        $ticketSalesAccount = $this->makeAccount('Ventes de billets (test revenu)', AccountType::TicketSales);
        foreach ([['TICKET_SALE', 99_000], ['ENTREE_DE_CAISSE', 20_000]] as [$referenceType, $amount]) {
            (new AccountTransaction)->forceFill([
                'account_id' => $ticketSalesAccount->id,
                'amount' => $amount,
                'transaction_type' => AccountTransactionType::Credit,
                'category' => AccountTransactionCategory::Revenue,
                'label' => 'test',
                'reference_type' => $referenceType,
                'reference_id' => null,
                'created_at' => '2019-06-15 10:00:00',
                'updated_at' => '2019-06-15 10:00:00',
            ])->save();
        }

        $report = $this->profitService->computeProfit('2019-06-01', '2019-06-30');

        $this->assertSame(50_000 + 20_000, $report->totalRevenue);
        $this->assertSame(50_000, $this->profitService->ticketSalesOfCostReportedDeparts('2019-06-01', '2019-06-30'));
    }
}
