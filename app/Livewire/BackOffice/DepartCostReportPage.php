<?php

namespace App\Livewire\BackOffice;

use App\Enums\PermissionName;
use App\Models\Account;
use App\Models\Bus;
use App\Models\Caisse;
use App\Models\Depart;
use App\Models\DepartCostReport;
use App\Models\DepartCostReportBusRental;
use App\Services\DepartCostReportPaymentService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Back office "Bilan du départ" page: shows the financial report of a départ, or the form to
 * record it when none exists yet (and to edit it afterwards).
 *
 * The bilan stores, per bus, the owner's name and the rental fee paid, plus free-form notes.
 * Ticket sales are computed live from the départ's tickets and never stored, so they always
 * match the real bookings.
 */
#[Layout('components.layouts.back-office')]
class DepartCostReportPage extends Component
{
    public Depart $depart;

    public bool $isEditing = false;

    public string $notes = '';

    /**
     * One form row per bus of the départ, keyed by bus id.
     *
     * @var array<int, array{busOwnerName: string, rentalFee: string}>
     */
    public array $busRentalRows = [];

    public bool $showCashOutModal = false;

    /**
     * Amount paid to each bus owner in this cash-out, keyed by bus id.
     *
     * @var array<int, string>
     */
    public array $paidAmountByBusId = [];

    /**
     * Caisses to withdraw from. With a single row the amount is always the total paid.
     *
     * @var array<int, array{caisseId: string, amount: string}>
     */
    public array $cashOutCaisseRows = [];

    /**
     * Accounts to debit. With a single row the amount is always the total paid.
     *
     * @var array<int, array{accountId: string, amount: string}>
     */
    public array $cashOutAccountRows = [];

    public string $cashOutLabel = '';

    public ?string $flashStatusMessage = null;

    public ?string $flashErrorMessage = null;

    public function mount(Depart $depart): void
    {
        $this->depart = $depart;

        $this->fillFormFromSavedCostReport();

        // A départ with no bilan yet opens straight on the form: recording a bilan needs no permission.
        $this->isEditing = $this->savedCostReport === null;
    }

    #[Computed]
    public function savedCostReport(): ?DepartCostReport
    {
        return $this->depart->costReport()->with('busRentals')->first();
    }

    /**
     * @return Collection<int, Bus>
     */
    #[Computed]
    public function buses(): Collection
    {
        return $this->depart->buses()->orderBy('id')->get(['id', 'name']);
    }

    /**
     * Distinct owner names already recorded on any bilan, offered as suggestions in the form.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function knownOwnerNames(): array
    {
        return DepartCostReportBusRental::query()
            ->distinct()
            ->orderBy('bus_owner_name')
            ->pluck('bus_owner_name')
            ->all();
    }

    /**
     * Ticket sales amount (FCFA) of each bus, keyed by bus id.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function ticketSalesByBusId(): array
    {
        return $this->depart->ticketSalesAmountByBusId();
    }

    #[Computed]
    public function totalTicketSales(): int
    {
        return array_sum($this->ticketSalesByBusId);
    }

    /**
     * Rental fee being typed in the form, so the summary reacts live while editing.
     */
    #[Computed]
    public function formTotalRentalFees(): int
    {
        return (int) collect($this->busRentalRows)
            ->sum(fn (array $row): int => max(0, (int) $row['rentalFee']));
    }

    /**
     * Total typed as paid to the bus owners in the cash-out form.
     */
    #[Computed]
    public function cashOutTotal(): int
    {
        return (int) collect($this->paidAmountByBusId)
            ->sum(fn (string $amount): int => max(0, (int) $amount));
    }

    /**
     * Caisses that can still be debited, with their balance.
     *
     * @return array<int, array{id: int, name: string, balance: int}>
     */
    #[Computed]
    public function cashOutCaisseOptions(): array
    {
        return Caisse::query()
            ->where('closed', false)
            ->orderBy('name')
            ->get()
            ->reject(fn (Caisse $caisse): bool => $caisse->isLockedForToday())
            ->map(fn (Caisse $caisse): array => ['id' => $caisse->id, 'name' => $caisse->name, 'balance' => $caisse->balance])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id: int, name: string, balance: int}>
     */
    #[Computed]
    public function cashOutAccountOptions(): array
    {
        return Account::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (Account $account): array => ['id' => $account->id, 'name' => $account->name, 'balance' => $account->balance])
            ->all();
    }

    public function openCashOutModal(): void
    {
        if (! $this->ensureFullAccessOrFlash() || $this->savedCostReport === null) {
            return;
        }

        $this->resetValidation();
        $this->paidAmountByBusId = $this->savedCostReport->busRentals
            ->mapWithKeys(fn ($rental): array => [$rental->bus_id => ''])
            ->all();
        $this->cashOutCaisseRows = [['caisseId' => '', 'amount' => '']];
        $this->cashOutAccountRows = [['accountId' => '', 'amount' => '']];
        $this->cashOutLabel = 'Location de bus '.$this->depart->identifier(with_trajet_prefix: true);
        unset($this->cashOutTotal);
        $this->showCashOutModal = true;
    }

    public function closeCashOutModal(): void
    {
        $this->showCashOutModal = false;
    }

    public function updatedPaidAmountByBusId(): void
    {
        unset($this->cashOutTotal);
        $this->fillSingleRowAmountsWithTotal();
    }

    public function addCashOutCaisseRow(): void
    {
        $this->cashOutCaisseRows[] = ['caisseId' => '', 'amount' => ''];
        $this->fillSingleRowAmountsWithTotal();
    }

    public function removeCashOutCaisseRow(int $rowIndex): void
    {
        unset($this->cashOutCaisseRows[$rowIndex]);
        $this->cashOutCaisseRows = array_values($this->cashOutCaisseRows);
        $this->fillSingleRowAmountsWithTotal();
    }

    public function addCashOutAccountRow(): void
    {
        $this->cashOutAccountRows[] = ['accountId' => '', 'amount' => ''];
        $this->fillSingleRowAmountsWithTotal();
    }

    public function removeCashOutAccountRow(int $rowIndex): void
    {
        unset($this->cashOutAccountRows[$rowIndex]);
        $this->cashOutAccountRows = array_values($this->cashOutAccountRows);
        $this->fillSingleRowAmountsWithTotal();
    }

    /**
     * Money-out: restricted to full-access users, re-validated server side, and executed by
     * DepartCostReportPaymentService inside one transaction (all caisse/account/bus rows or nothing).
     */
    public function saveCashOut(DepartCostReportPaymentService $departCostReportPaymentService): void
    {
        $this->flashStatusMessage = null;
        $this->flashErrorMessage = null;

        if (! $this->ensureFullAccessOrFlash()) {
            return;
        }

        $savedCostReport = $this->savedCostReport;
        if ($savedCostReport === null) {
            $this->flashErrorMessage = "Enregistrez d'abord le bilan.";

            return;
        }

        unset($this->cashOutTotal);
        $this->fillSingleRowAmountsWithTotal();
        $totalPaid = $this->cashOutTotal;

        $this->validate([
            'paidAmountByBusId.*' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'cashOutLabel' => ['required', 'string', 'max:255'],
            'cashOutCaisseRows' => ['required', 'array', 'min:1'],
            'cashOutCaisseRows.*.caisseId' => ['required', 'integer', 'distinct', 'exists:caisses,id'],
            'cashOutCaisseRows.*.amount' => ['required', 'integer', 'min:1'],
            'cashOutAccountRows' => ['required', 'array', 'min:1'],
            'cashOutAccountRows.*.accountId' => ['required', 'integer', 'distinct', 'exists:accounts,id'],
            'cashOutAccountRows.*.amount' => ['required', 'integer', 'min:1'],
        ], [], [
            'paidAmountByBusId.*' => 'montant payé',
            'cashOutLabel' => 'libellé',
            'cashOutCaisseRows.*.caisseId' => 'caisse',
            'cashOutCaisseRows.*.amount' => 'montant de la caisse',
            'cashOutAccountRows.*.accountId' => 'compte',
            'cashOutAccountRows.*.amount' => 'montant du compte',
        ]);

        if ($totalPaid <= 0) {
            $this->addError('paidAmountByBusId', 'Indiquez le montant payé pour au moins un bus.');

            return;
        }

        $amountByCaisseId = collect($this->cashOutCaisseRows)
            ->mapWithKeys(fn (array $row): array => [(int) $row['caisseId'] => (int) $row['amount']])->all();
        $amountByAccountId = collect($this->cashOutAccountRows)
            ->mapWithKeys(fn (array $row): array => [(int) $row['accountId'] => (int) $row['amount']])->all();

        if (array_sum($amountByCaisseId) !== $totalPaid) {
            $this->addError('cashOutCaisseRows', "Le total des caisses doit être égal au total payé ({$totalPaid} F).");

            return;
        }

        if (array_sum($amountByAccountId) !== $totalPaid) {
            $this->addError('cashOutAccountRows', "Le total des comptes doit être égal au total payé ({$totalPaid} F).");

            return;
        }

        $paidAmountByBusId = collect($this->paidAmountByBusId)
            ->map(fn (string $amount): int => (int) $amount)->all();

        try {
            $departCostReportPaymentService->recordBusRentalPayments(
                $savedCostReport,
                $paidAmountByBusId,
                $amountByCaisseId,
                $amountByAccountId,
                trim($this->cashOutLabel),
                auth()->id(),
            );
        } catch (\Throwable $exception) {
            $this->flashErrorMessage = $exception->getMessage();

            return;
        }

        unset($this->savedCostReport, $this->cashOutCaisseOptions, $this->cashOutAccountOptions);
        $this->showCashOutModal = false;
        $this->flashStatusMessage = "Sortie de {$totalPaid} F enregistrée en caisse.";
    }

    public function startEditing(): void
    {
        $this->fillFormFromSavedCostReport();
        $this->isEditing = true;
    }

    public function cancelEditing(): void
    {
        $this->resetValidation();
        $this->fillFormFromSavedCostReport();
        $this->isEditing = false;
    }

    public function save(): void
    {
        $this->flashStatusMessage = null;
        $this->flashErrorMessage = null;

        $this->validate();

        $paidAmountByBusId = $this->savedCostReport?->busRentals->pluck('paid_amount', 'bus_id') ?? collect();
        foreach ($this->busRentalRows as $busId => $busRentalRow) {
            if ((int) $busRentalRow['rentalFee'] < (int) $paidAmountByBusId->get($busId, 0)) {
                $this->addError("busRentalRows.{$busId}.rentalFee", 'Les frais de location ne peuvent pas être inférieurs au montant déjà payé ('.$paidAmountByBusId->get($busId).' F).');

                return;
            }
        }

        DB::transaction(function (): void {
            $userId = auth()->id();

            $costReport = $this->depart->costReport()->first()
                ?? new DepartCostReport(['depart_id' => $this->depart->id, 'created_by' => $userId]);

            $costReport->fill([
                'notes' => trim($this->notes) === '' ? null : trim($this->notes),
                'updated_by' => $userId,
            ])->save();

            foreach ($this->busRentalRows as $busId => $busRentalRow) {
                $costReport->busRentals()->updateOrCreate(
                    ['bus_id' => $busId],
                    [
                        'bus_owner_name' => trim($busRentalRow['busOwnerName']),
                        'rental_fee' => (int) $busRentalRow['rentalFee'],
                    ],
                );
            }
        });

        unset($this->savedCostReport, $this->formTotalRentalFees);

        $this->isEditing = false;
        $this->flashStatusMessage = 'Le bilan du départ a été enregistré.';
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:5000'],
            'busRentalRows' => ['required', 'array', 'min:1'],
            'busRentalRows.*.busOwnerName' => ['required', 'string', 'max:255'],
            'busRentalRows.*.rentalFee' => ['required', 'integer', 'min:0', 'max:100000000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        $attributes = ['notes' => 'notes', 'busRentalRows' => 'bus'];

        foreach ($this->buses as $bus) {
            $attributes["busRentalRows.{$bus->id}.busOwnerName"] = "propriétaire ({$bus->name})";
            $attributes["busRentalRows.{$bus->id}.rentalFee"] = "frais de location ({$bus->name})";
        }

        return $attributes;
    }

    /**
     * Rebuild the form state (one row per bus of the départ) from the saved bilan, if any.
     */
    private function fillFormFromSavedCostReport(): void
    {
        $savedRentalsByBusId = $this->depart->costReport()->first()?->busRentals->keyBy('bus_id') ?? collect();

        $this->notes = (string) $this->depart->costReport()->value('notes');
        $this->busRentalRows = $this->depart->buses()->orderBy('id')->get(['id'])
            ->mapWithKeys(fn (Bus $bus): array => [
                $bus->id => [
                    'busOwnerName' => (string) $savedRentalsByBusId->get($bus->id)?->bus_owner_name,
                    'rentalFee' => (string) ($savedRentalsByBusId->get($bus->id)?->rental_fee ?? ''),
                ],
            ])
            ->all();
    }

    /**
     * With a single caisse (or account) row, its amount is by definition the total paid.
     */
    private function fillSingleRowAmountsWithTotal(): void
    {
        $totalAsString = $this->cashOutTotal > 0 ? (string) $this->cashOutTotal : '';

        if (count($this->cashOutCaisseRows) === 1) {
            $this->cashOutCaisseRows[0]['amount'] = $totalAsString;
        }

        if (count($this->cashOutAccountRows) === 1) {
            $this->cashOutAccountRows[0]['amount'] = $totalAsString;
        }
    }

    private function ensureFullAccessOrFlash(): bool
    {
        if (PermissionName::FullAccess->allowedForCurrentUser()) {
            return true;
        }

        $this->flashErrorMessage = PermissionName::FullAccess->unauthorizedMessage();

        return false;
    }

    public function render(): View
    {
        return view('livewire.back-office.depart-cost-report-page')
            ->title('Bilan — '.$this->depart->identifier(with_trajet_prefix: true));
    }
}
