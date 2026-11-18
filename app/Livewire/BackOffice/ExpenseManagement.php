<?php

namespace App\Livewire\BackOffice;

use App\Enums\PermissionName;
use App\Models\ExpenseType;
use App\Models\MonthlyExpenseBudget;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Back office "Gestion dépenses" page (Finances menu): plan the monthly costs, per expense type
 * flagged "planned monthly" (Types de dépense page), for the current month and the next six. Every month is planned independently (see
 * MonthlyExpenseBudget); a blank or zero cell means "no fixed cost for that type that month".
 *
 * "Copier le mois précédent" only fills the inputs of a column — nothing is stored until
 * "Enregistrer". Whether a month's costs are covered is shown by FixedCostCoverageCard / Badge.
 *
 * Restricted to full-access users, like the rest of the Finances menu.
 */
#[Layout('components.layouts.back-office')]
class ExpenseManagement extends Component
{
    public const PLANNED_MONTHS_COUNT = 7;

    private const MAXIMUM_BUDGET_AMOUNT = 1_000_000_000;

    /** @var array<int, array<string, string>> amount typed per expense type id, then per "Y-m" month key */
    public array $budgetAmounts = [];

    public function mount(): void
    {
        PermissionName::FullAccess->authorizeForCurrentUser();

        $this->loadSavedBudgets();
    }

    /**
     * The planned months, keyed by "Y-m": the current month first, then the next six.
     *
     * @return array<string, CarbonImmutable>
     */
    #[Computed]
    public function plannedMonths(): array
    {
        $firstMonth = CarbonImmutable::now()->startOfMonth();
        $plannedMonths = [];

        for ($monthOffset = 0; $monthOffset < self::PLANNED_MONTHS_COUNT; $monthOffset++) {
            $month = $firstMonth->addMonths($monthOffset);
            $plannedMonths[$month->format('Y-m')] = $month;
        }

        return $plannedMonths;
    }

    /**
     * Active expense types flagged "planned monthly", plus any other type that still carries a
     * budget in the planned months (deactivated, or unflagged since), so no budget stays hidden.
     *
     * @return array<int, array{id: int, name: string, isActive: bool}>
     */
    #[Computed]
    public function expenseTypeRows(): array
    {
        $expenseTypeIdsWithBudget = MonthlyExpenseBudget::query()
            ->where('month', '>=', $this->firstPlannedMonth()->toDateString())
            ->pluck('expense_type_id');

        return ExpenseType::query()
            ->where(fn ($query) => $query->where(fn ($plannableQuery) => $plannableQuery->where('is_active', true)->where('is_planned_monthly', true))->orWhereIn('id', $expenseTypeIdsWithBudget))
            ->orderBy('name')
            ->get()
            ->map(fn (ExpenseType $expenseType): array => [
                'id' => $expenseType->id,
                'name' => $expenseType->name,
                'isActive' => $expenseType->is_active,
            ])
            ->all();
    }

    /**
     * Total planned for each month key, from what is currently typed in the grid.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function monthTotals(): array
    {
        $monthTotals = array_fill_keys(array_keys($this->plannedMonths()), 0);

        foreach ($this->budgetAmounts as $amountsByMonthKey) {
            foreach ($amountsByMonthKey as $monthKey => $typedAmount) {
                if (isset($monthTotals[$monthKey]) && ctype_digit(trim((string) $typedAmount))) {
                    $monthTotals[$monthKey] += (int) $typedAmount;
                }
            }
        }

        return $monthTotals;
    }

    /**
     * Fill a month's inputs from the previous month: the previous column of the grid (typed values
     * included) or, for the current month, last month's saved budgets. Nothing is stored yet.
     */
    public function copyPreviousMonth(string $monthKey): void
    {
        PermissionName::FullAccess->authorizeForCurrentUser();

        $monthKeys = array_keys($this->plannedMonths());
        $columnIndex = array_search($monthKey, $monthKeys, true);

        if ($columnIndex === false) {
            return;
        }

        $previousMonthAmounts = $columnIndex > 0
            ? $this->typedAmountsOfMonth($monthKeys[$columnIndex - 1])
            : MonthlyExpenseBudget::query()
                ->where('month', $this->plannedMonths()[$monthKey]->subMonth()->toDateString())
                ->pluck('amount', 'expense_type_id')
                ->map(fn (int $amount): string => (string) $amount)
                ->all();

        foreach ($this->expenseTypeRows() as $expenseTypeRow) {
            $this->budgetAmounts[$expenseTypeRow['id']][$monthKey] = $previousMonthAmounts[$expenseTypeRow['id']] ?? '';
        }
    }

    public function saveBudgets(): void
    {
        PermissionName::FullAccess->authorizeForCurrentUser();

        $this->validate([
            'budgetAmounts.*.*' => ['nullable', 'integer', 'min:0', 'max:'.self::MAXIMUM_BUDGET_AMOUNT],
        ], attributes: ['budgetAmounts.*.*' => 'montant']);

        DB::transaction(function (): void {
            foreach ($this->expenseTypeRows() as $expenseTypeRow) {
                foreach ($this->plannedMonths() as $monthKey => $month) {
                    $amount = (int) ($this->budgetAmounts[$expenseTypeRow['id']][$monthKey] ?? 0);
                    $budgetIdentity = ['expense_type_id' => $expenseTypeRow['id'], 'month' => $month->toDateString()];

                    if ($amount > 0) {
                        MonthlyExpenseBudget::updateOrCreate($budgetIdentity, ['amount' => $amount]);
                    } else {
                        MonthlyExpenseBudget::where($budgetIdentity)->delete();
                    }
                }
            }
        });

        $this->loadSavedBudgets();
        session()->flash('status', 'Les charges fixes ont été enregistrées.');
    }

    public function render(): View
    {
        return view('livewire.back-office.expense-management')->title('Gestion dépenses — Back Office');
    }

    private function firstPlannedMonth(): CarbonImmutable
    {
        return array_values($this->plannedMonths())[0];
    }

    private function loadSavedBudgets(): void
    {
        $this->budgetAmounts = [];

        $savedBudgets = MonthlyExpenseBudget::query()
            ->where('month', '>=', $this->firstPlannedMonth()->toDateString())
            ->get();

        foreach ($savedBudgets as $savedBudget) {
            $this->budgetAmounts[$savedBudget->expense_type_id][$savedBudget->month->format('Y-m')] = (string) $savedBudget->amount;
        }

        unset($this->expenseTypeRows, $this->monthTotals);
    }

    /**
     * @return array<int, string>
     */
    private function typedAmountsOfMonth(string $monthKey): array
    {
        $typedAmounts = [];

        foreach ($this->budgetAmounts as $expenseTypeId => $amountsByMonthKey) {
            $typedAmounts[$expenseTypeId] = (string) ($amountsByMonthKey[$monthKey] ?? '');
        }

        return $typedAmounts;
    }
}
