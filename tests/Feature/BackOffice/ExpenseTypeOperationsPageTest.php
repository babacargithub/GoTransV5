<?php

namespace Tests\Feature\BackOffice;

use App\Enums\AccountTransactionCategory;
use App\Enums\AccountType;
use App\Livewire\BackOffice\ExpenseTypeOperations;
use App\Livewire\BackOffice\ProfitReportPage;
use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Caisse;
use App\Models\ExpenseType;
use App\Services\AccountService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ExpenseTypeOperationsPageTest extends TestCase
{
    use DatabaseTransactions;

    private Caisse $caisse;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*' => Http::response([], 503)]);
        $this->caisse = Caisse::create(['name' => 'Caisse opérations (test)', 'balance' => 0]);
        $this->account = Account::create(['name' => 'Compte opérations (test)', 'account_type' => AccountType::Management, 'balance' => 0, 'is_active' => true]);
        app(AccountService::class)->processEntreeDeCaisse($this->caisse, $this->account, 500_000, 'Fonds');
    }

    private function spend(int $amount, string $label, ?ExpenseType $expenseType): void
    {
        app(AccountService::class)->processSortieDeCaisse(
            $this->caisse->fresh(), $amount, $label, [$this->account->id],
            categoryOverride: AccountTransactionCategory::Expense,
            expenseTypeId: $expenseType?->id,
        );
    }

    public function test_the_page_is_restricted_to_full_access_users(): void
    {
        $expenseType = ExpenseType::factory()->create();

        $this->get(route('back-office.expense-types.operations', $expenseType))->assertRedirect(route('login'));

        $this->actingAs($this->createUserWithPermissions([]))
            ->get(route('back-office.expense-types.operations', $expenseType))
            ->assertForbidden();

        $this->actingAs($this->createUserWithFullAccess())
            ->get(route('back-office.expense-types.operations', $expenseType))
            ->assertOk();
    }

    public function test_it_lists_only_the_operations_of_that_type_within_the_period_with_their_total_and_caisse(): void
    {
        $airtimeType = ExpenseType::factory()->create(['name' => 'Airtime opérations (test)']);
        $otherType = ExpenseType::factory()->create();
        $this->spend(10_000, 'Airtime lundi', $airtimeType);
        $this->spend(5_000, 'Airtime mardi', $airtimeType);
        $this->spend(7_000, 'Autre dépense', $otherType);
        $oldOperation = AccountTransaction::where('label', 'Airtime mardi')->where('account_id', $this->account->id)->firstOrFail();
        $oldOperation->forceFill(['created_at' => now()->subYear()])->save();

        $component = Livewire::actingAs($this->createUserWithFullAccess())
            ->test(ExpenseTypeOperations::class, ['expenseType' => $airtimeType])
            ->set('dateFrom', now()->startOfMonth()->toDateString())
            ->set('dateTo', now()->endOfMonth()->toDateString());

        $rows = $component->instance()->operationRows;

        $this->assertCount(1, $rows);
        $this->assertSame('Airtime lundi', $rows[0]['label']);
        $this->assertSame(10_000, $rows[0]['amount']);
        $this->assertSame('Caisse opérations (test)', $rows[0]['caisseNames']);
        $this->assertSame(10_000, $component->instance()->totalAmount);
        $component->assertSee('Airtime opérations (test)');
    }

    public function test_the_unclassified_page_lists_expenses_without_a_type(): void
    {
        $this->spend(3_000, 'Dépense sans type (test)', null);

        $component = Livewire::actingAs($this->createUserWithFullAccess())
            ->test(ExpenseTypeOperations::class)
            ->set('dateFrom', now()->startOfMonth()->toDateString())
            ->set('dateTo', now()->endOfMonth()->toDateString());

        $this->assertContains('Dépense sans type (test)', array_column($component->instance()->operationRows, 'label'));
        $component->assertSee('Non classé');
    }

    public function test_the_balance_sheet_lines_link_to_the_operations_page_on_the_same_period(): void
    {
        $airtimeType = ExpenseType::factory()->create(['name' => 'Airtime lien (test)']);
        $this->spend(10_000, 'Airtime', $airtimeType);
        $from = now()->startOfMonth()->toDateString();
        $to = now()->endOfMonth()->toDateString();

        Livewire::actingAs($this->createUserWithFullAccess())
            ->test(ProfitReportPage::class)
            ->assertSeeHtml(e(route('back-office.expense-types.operations', ['expenseType' => $airtimeType->id, 'du' => $from, 'au' => $to])));
    }

    public function test_the_report_period_is_restored_from_the_url(): void
    {
        Livewire::withQueryParams(['du' => '2026-11-01', 'au' => '2026-11-30'])
            ->actingAs($this->createUserWithFullAccess())
            ->test(ProfitReportPage::class)
            ->assertSet('profitReportDateFrom', '2026-11-01')
            ->assertSet('profitReportDateTo', '2026-11-30');
    }
}
