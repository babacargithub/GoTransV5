<?php

namespace Tests\Feature\BackOffice;

use App\Livewire\BackOffice\ExpenseManagement;
use App\Livewire\BackOffice\FixedCostCoverageCard;
use App\Models\ExpenseType;
use App\Models\MonthlyExpenseBudget;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class ExpenseManagementPageTest extends TestCase
{
    use DatabaseTransactions;

    private function currentMonthKey(): string
    {
        return now()->format('Y-m');
    }

    private function nextMonthKey(): string
    {
        return now()->startOfMonth()->addMonth()->format('Y-m');
    }

    public function test_the_page_is_restricted_to_full_access_users(): void
    {
        $this->get(route('back-office.depenses.index'))->assertRedirect(route('login'));

        $this->actingAs($this->createUserWithPermissions([]))
            ->get(route('back-office.depenses.index'))
            ->assertForbidden();

        $this->actingAs($this->createUserWithFullAccess())
            ->get(route('back-office.depenses.index'))
            ->assertOk()
            ->assertSee('Gestion dépenses');
    }

    public function test_the_grid_spans_the_current_month_and_the_next_six(): void
    {
        $component = Livewire::actingAs($this->createUserWithFullAccess())->test(ExpenseManagement::class);

        $plannedMonthKeys = array_keys($component->instance()->plannedMonths());

        $this->assertCount(7, $plannedMonthKeys);
        $this->assertSame($this->currentMonthKey(), $plannedMonthKeys[0]);
        $this->assertSame(now()->startOfMonth()->addMonths(6)->format('Y-m'), $plannedMonthKeys[6]);
    }

    public function test_saving_stores_each_month_independently_and_clears_blank_cells(): void
    {
        $salaryType = ExpenseType::factory()->plannedMonthly()->create();
        $clearedType = ExpenseType::factory()->plannedMonthly()->create();
        MonthlyExpenseBudget::factory()->create(['expense_type_id' => $clearedType->id, 'month' => now()->startOfMonth()->toDateString(), 'amount' => 5_000]);

        Livewire::actingAs($this->createUserWithFullAccess())
            ->test(ExpenseManagement::class)
            ->set("budgetAmounts.{$salaryType->id}.{$this->currentMonthKey()}", '200000')
            ->set("budgetAmounts.{$salaryType->id}.{$this->nextMonthKey()}", '300000')
            ->set("budgetAmounts.{$clearedType->id}.{$this->currentMonthKey()}", '')
            ->call('saveBudgets')
            ->assertHasNoErrors();

        $this->assertSame(200_000, MonthlyExpenseBudget::where('expense_type_id', $salaryType->id)->where('month', now()->startOfMonth()->toDateString())->value('amount'));
        $this->assertSame(300_000, MonthlyExpenseBudget::where('expense_type_id', $salaryType->id)->where('month', now()->startOfMonth()->addMonth()->toDateString())->value('amount'));
        $this->assertDatabaseMissing('monthly_expense_budgets', ['expense_type_id' => $clearedType->id]);
    }

    public function test_saving_rejects_non_numeric_and_negative_amounts(): void
    {
        $salaryType = ExpenseType::factory()->plannedMonthly()->create();

        $component = Livewire::actingAs($this->createUserWithFullAccess())->test(ExpenseManagement::class);

        $component->set("budgetAmounts.{$salaryType->id}.{$this->currentMonthKey()}", 'abc')->call('saveBudgets')->assertHasErrors();
        $component->set("budgetAmounts.{$salaryType->id}.{$this->currentMonthKey()}", '-10')->call('saveBudgets')->assertHasErrors();

        $this->assertDatabaseMissing('monthly_expense_budgets', ['expense_type_id' => $salaryType->id]);
    }

    public function test_copy_previous_month_fills_the_inputs_without_saving(): void
    {
        $salaryType = ExpenseType::factory()->plannedMonthly()->create();
        MonthlyExpenseBudget::factory()->create(['expense_type_id' => $salaryType->id, 'month' => now()->startOfMonth()->subMonth()->toDateString(), 'amount' => 150_000]);

        $component = Livewire::actingAs($this->createUserWithFullAccess())
            ->test(ExpenseManagement::class)
            ->call('copyPreviousMonth', $this->currentMonthKey())
            ->assertSet("budgetAmounts.{$salaryType->id}.{$this->currentMonthKey()}", '150000')
            ->call('copyPreviousMonth', $this->nextMonthKey())
            ->assertSet("budgetAmounts.{$salaryType->id}.{$this->nextMonthKey()}", '150000');

        $this->assertDatabaseMissing('monthly_expense_budgets', ['expense_type_id' => $salaryType->id, 'month' => now()->startOfMonth()->toDateString()]);
    }

    public function test_the_coverage_card_invites_planning_when_the_month_has_no_fixed_costs(): void
    {
        Livewire::actingAs($this->createUserWithFullAccess())
            ->test(FixedCostCoverageCard::class)
            ->set('coverageMonthKey', now()->startOfMonth()->addMonths(6)->format('Y-m'))
            ->assertSee('Charges fixes non planifiées pour ce mois');
    }
}
