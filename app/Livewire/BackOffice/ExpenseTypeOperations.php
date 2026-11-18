<?php

namespace App\Livewire\BackOffice;

use App\Enums\CaisseTransactionType;
use App\Enums\PermissionName;
use App\Models\AccountTransaction;
use App\Models\CaisseTransaction;
use App\Models\ExpenseType;
use App\Services\ProfitService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Back office page listing the caisse operations behind one line of the "Dépenses" balance of the
 * Revenus page: every expense of one expense type (or the unclassified ones when no type is given)
 * over the selected period. Reads ProfitService::expenseOperationsOfType(), the same source as the
 * balance, so the total shown here equals the line the user clicked.
 *
 * Restricted to full-access users, like the Finances pages.
 */
#[Layout('components.layouts.back-office')]
class ExpenseTypeOperations extends Component
{
    public ?ExpenseType $expenseType = null;

    #[Url(as: 'du')]
    public string $dateFrom = '';

    #[Url(as: 'au')]
    public string $dateTo = '';

    public function mount(?ExpenseType $expenseType = null): void
    {
        PermissionName::FullAccess->authorizeForCurrentUser();

        $this->expenseType = $expenseType;
    }

    public function expenseTypeName(): string
    {
        return $this->expenseType?->name ?? 'Non classé';
    }

    /**
     * Each operation with the caisse(s) the money left from. The caisse withdrawal of an operation is
     * found by its label and timestamp, as both ledgers are written together in one transaction.
     *
     * @return array<int, array{id: int, date: string, label: string, amount: int, accountName: string, caisseNames: string, userName: string}>
     */
    #[Computed]
    public function operationRows(): array
    {
        $operations = app(ProfitService::class)->expenseOperationsOfType(
            $this->expenseType?->id,
            $this->dateFrom !== '' ? $this->dateFrom : null,
            $this->dateTo !== '' ? $this->dateTo : null,
        );

        $caisseNamesByLabelAndDate = CaisseTransaction::query()
            ->with('caisse')
            ->where('transaction_type', CaisseTransactionType::Withdraw->value)
            ->whereIn('label', $operations->pluck('label')->unique())
            ->whereIn('created_at', $operations->pluck('created_at')->unique())
            ->get()
            ->groupBy(fn (CaisseTransaction $withdrawal): string => $withdrawal->label.'|'.$withdrawal->created_at)
            ->map(fn ($withdrawals): string => $withdrawals->pluck('caisse.name')->unique()->implode(', '));

        return $operations
            ->map(fn (AccountTransaction $operation): array => [
                'id' => $operation->id,
                'date' => $operation->created_at->isoFormat('ddd D MMM YYYY, HH:mm'),
                'label' => (string) $operation->label,
                'amount' => $operation->amount,
                'accountName' => $operation->account->name,
                'caisseNames' => $caisseNamesByLabelAndDate[$operation->label.'|'.$operation->created_at] ?? '—',
                'userName' => $operation->user?->name ?? '—',
            ])
            ->all();
    }

    #[Computed]
    public function totalAmount(): int
    {
        return (int) array_sum(array_column($this->operationRows, 'amount'));
    }

    /**
     * Back to the Revenus page, on the same period.
     */
    public function backToReportUrl(): string
    {
        return route('back-office.revenus.index', array_filter([
            'du' => $this->dateFrom,
            'au' => $this->dateTo,
        ]));
    }

    public function render(): View
    {
        return view('livewire.back-office.expense-type-operations')->title($this->expenseTypeName().' — Dépenses');
    }
}
