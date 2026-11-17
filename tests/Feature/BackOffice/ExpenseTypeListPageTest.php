<?php

namespace Tests\Feature\BackOffice;

use App\Enums\AccountTransactionCategory;
use App\Enums\AccountType;
use App\Enums\ExpenseTypeCode;
use App\Livewire\BackOffice\CaisseBalancesPage;
use App\Livewire\BackOffice\ExpenseTypeList;
use App\Models\Account;
use App\Models\Caisse;
use App\Models\ExpenseType;
use App\Services\AccountService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class ExpenseTypeListPageTest extends TestCase
{
    use DatabaseTransactions;

    public function test_the_page_is_restricted_to_full_access_users_and_linked_from_the_finances_menu(): void
    {
        $this->get(route('back-office.expense-types.index'))->assertRedirect(route('login'));

        $this->actingAs($this->createUserWithPermissions([]))
            ->get(route('back-office.expense-types.index'))
            ->assertForbidden();

        $fullAccessUser = $this->createUserWithFullAccess();

        $this->actingAs($fullAccessUser)
            ->get(route('back-office.expense-types.index'))
            ->assertOk()
            ->assertSee('Types de dépense');

        $this->actingAs($fullAccessUser)
            ->get(route('back-office.cost-reports.index'))
            ->assertSee(route('back-office.expense-types.index'), false);
    }

    public function test_it_lists_expense_types_and_creates_a_new_one(): void
    {
        $component = Livewire::actingAs($this->createUserWithFullAccess())
            ->test(ExpenseTypeList::class)
            ->assertSee('Location de bus')
            ->call('openCreateExpenseType')
            ->set('expenseTypeName', 'Airtime (test page)')
            ->call('saveExpenseType')
            ->assertHasNoErrors();

        $created = ExpenseType::where('name', 'Airtime (test page)')->firstOrFail();
        $this->assertFalse($created->is_system);
        $this->assertTrue($created->is_active);
        $component->assertSee('Airtime (test page)');
    }

    public function test_names_must_be_unique_but_a_type_can_keep_its_own_name_when_edited(): void
    {
        $expenseType = ExpenseType::factory()->create(['name' => 'Type unique (test)']);

        $component = Livewire::actingAs($this->createUserWithFullAccess())
            ->test(ExpenseTypeList::class)
            ->call('openCreateExpenseType')
            ->set('expenseTypeName', 'Location de bus')
            ->call('saveExpenseType')
            ->assertHasErrors(['expenseTypeName']);

        $component
            ->call('openEditExpenseType', $expenseType->id)
            ->set('expenseTypeName', 'Type unique (test)')
            ->call('saveExpenseType')
            ->assertHasNoErrors();
    }

    public function test_a_system_type_can_be_renamed_and_keeps_its_code(): void
    {
        $locationDeBus = ExpenseType::findByCode(ExpenseTypeCode::LocationDeBus);

        Livewire::actingAs($this->createUserWithFullAccess())
            ->test(ExpenseTypeList::class)
            ->call('openEditExpenseType', $locationDeBus->id)
            ->set('expenseTypeName', 'Location des bus')
            ->call('saveExpenseType')
            ->assertHasNoErrors();

        $locationDeBus->refresh();
        $this->assertSame('Location des bus', $locationDeBus->name);
        $this->assertSame(ExpenseTypeCode::LocationDeBus->value, $locationDeBus->code);
    }

    public function test_a_type_can_be_deactivated_and_reactivated(): void
    {
        $expenseType = ExpenseType::factory()->create();

        $component = Livewire::actingAs($this->createUserWithFullAccess())->test(ExpenseTypeList::class);

        $component->call('toggleExpenseTypeActive', $expenseType->id);
        $this->assertFalse($expenseType->fresh()->is_active);

        $component->call('toggleExpenseTypeActive', $expenseType->id);
        $this->assertTrue($expenseType->fresh()->is_active);
    }

    public function test_a_type_can_be_flagged_and_unflagged_as_planned_monthly(): void
    {
        $expenseType = ExpenseType::factory()->create();

        $component = Livewire::actingAs($this->createUserWithFullAccess())->test(ExpenseTypeList::class);

        $component->call('toggleExpenseTypePlannedMonthly', $expenseType->id);
        $this->assertTrue($expenseType->fresh()->is_planned_monthly);

        $component->call('toggleExpenseTypePlannedMonthly', $expenseType->id);
        $this->assertFalse($expenseType->fresh()->is_planned_monthly);
    }

    public function test_only_salaries_transport_internet_and_advertising_are_planned_monthly_by_default(): void
    {
        $plannedCodes = [ExpenseTypeCode::SalairePdg, ExpenseTypeCode::SalaireEmploye, ExpenseTypeCode::TransportEmploye, ExpenseTypeCode::CreditEtPasseInternet, ExpenseTypeCode::Publicite];

        foreach (ExpenseTypeCode::cases() as $code) {
            $this->assertSame(in_array($code, $plannedCodes, true), ExpenseType::findByCode($code)->is_planned_monthly, $code->value);
        }
    }

    public function test_only_an_unused_non_system_type_can_be_deleted(): void
    {
        $unusedType = ExpenseType::factory()->create();
        $usedType = ExpenseType::factory()->create();
        $locationDeBus = ExpenseType::findByCode(ExpenseTypeCode::LocationDeBus);

        $caisse = Caisse::create(['name' => 'Caisse types (test)', 'balance' => 0]);
        $account = Account::create(['name' => 'Compte types (test)', 'account_type' => AccountType::Management, 'balance' => 0, 'is_active' => true]);
        $accountService = app(AccountService::class);
        $accountService->processEntreeDeCaisse($caisse, $account, 10_000, 'Fonds');
        $accountService->processSortieDeCaisse($caisse, 4_000, 'Dépense', [$account->id], categoryOverride: AccountTransactionCategory::Expense, expenseTypeId: $usedType->id);

        $component = Livewire::actingAs($this->createUserWithFullAccess())->test(ExpenseTypeList::class);

        foreach ([$usedType, $locationDeBus] as $protectedType) {
            $component->call('askToDeleteExpenseType', $protectedType->id)->call('confirmDeleteExpenseType');
            $this->assertNotNull(ExpenseType::find($protectedType->id));
        }

        $component->call('askToDeleteExpenseType', $unusedType->id)->call('confirmDeleteExpenseType');
        $this->assertNull(ExpenseType::find($unusedType->id));
    }

    public function test_a_deactivated_type_is_no_longer_offered_on_the_sortie_form(): void
    {
        $expenseType = ExpenseType::factory()->create(['name' => 'Type désactivé (test)', 'is_active' => false]);

        $this->assertNotContains(
            $expenseType->id,
            collect(Livewire::actingAs($this->createUserWithFullAccess())
                ->test(CaisseBalancesPage::class)
                ->get('expenseTypeOptions'))->pluck('id')->all(),
        );
    }
}
