<?php

namespace App\Livewire\BackOffice\Concerns;

use App\Enums\CaisseTransactionType;
use App\Enums\PermissionName;
use App\Models\Account;
use App\Models\Caisse;
use App\Services\AccountService;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;

/**
 * "Caisses" tab of the back-office Finance page: caisse CRUD, entrée/sortie de
 * caisse, transfer between caisses, day lock/unlock, and a lazy transactions
 * modal. Every mutation goes through App\Services\AccountService so the
 * SUM(caisse.balance) == SUM(account.balance) invariant is preserved.
 *
 * Requires the host to provide $flashStatusMessage / $flashErrorMessage,
 * resetFlashMessages() and ensurePermittedOrFlash() — see CaisseBalancesPage.
 */
trait ManagesCaisses
{
    public bool $showCaisseModal = false;

    public ?int $editingCaisseId = null;

    public string $caisseName = '';

    public bool $showDeleteCaisseModal = false;

    public ?int $deletingCaisseId = null;

    public bool $showEntreeModal = false;

    public ?int $entreeCaisseId = null;

    public ?int $entreeAccountId = null;

    public ?int $entreeAmount = null;

    public string $entreeLabel = '';

    public bool $showSortieModal = false;

    public ?int $sortieCaisseId = null;

    public ?int $sortieAmount = null;

    public string $sortieLabel = '';

    /** @var array<int, int> */
    public array $sortieAccountIds = [];

    public bool $showCaisseTransferModal = false;

    public ?int $transferFromCaisseId = null;

    public ?int $transferToCaisseId = null;

    public ?int $transferAmount = null;

    public ?string $transferDescription = null;

    public bool $showCaisseTransactionsModal = false;

    public ?int $viewingCaisseTransactionsId = null;

    /**
     * @return array<int, array{id: int, name: string, code: string|null, balance: int, isSystemCaisse: bool, closed: bool, isLockedForToday: bool}>
     */
    #[Computed]
    public function caisseRows(): array
    {
        return Caisse::query()
            ->orderBy('name')
            ->get()
            ->map(fn (Caisse $caisse): array => [
                'id' => $caisse->id,
                'name' => $caisse->name,
                'code' => $caisse->code,
                'balance' => $caisse->balance,
                'isSystemCaisse' => $caisse->isSystemCaisse(),
                'closed' => (bool) $caisse->closed,
                'isLockedForToday' => $caisse->isLockedForToday(),
            ])
            ->all();
    }

    public function caisseNameForId(?int $caisseId): ?string
    {
        return $caisseId === null ? null : Caisse::find($caisseId)?->name;
    }

    /* ================= créer / modifier ================= */

    public function openCreateCaisse(): void
    {
        $this->resetCaisseForm();
        $this->editingCaisseId = null;
        $this->showCaisseModal = true;
    }

    public function openEditCaisse(int $caisseId): void
    {
        $caisse = Caisse::findOrFail($caisseId);

        $this->editingCaisseId = $caisse->id;
        $this->caisseName = $caisse->name;
        $this->resetValidation();
        $this->showCaisseModal = true;
    }

    public function closeCaisseModal(): void
    {
        $this->showCaisseModal = false;
        $this->editingCaisseId = null;
        $this->resetValidation();
    }

    private function resetCaisseForm(): void
    {
        $this->caisseName = '';
        $this->resetValidation();
    }

    public function saveCaisse(): void
    {
        $this->resetFlashMessages();

        if (! $this->ensurePermittedOrFlash(PermissionName::ManageCaisse)) {
            return;
        }

        $validated = $this->validate([
            'caisseName' => ['required', 'string', 'max:255'],
        ], attributes: ['caisseName' => 'nom']);

        if ($this->editingCaisseId === null) {
            // Ad-hoc caisses created through the UI never get a code — only the
            // 4 built-in system caisses (Wave/OM/Ticket Cash/Caisse Principale,
            // seeded by the create_caisses_table migration) have one, since
            // payment-method routing depends on that code staying stable.
            Caisse::create(['name' => $validated['caisseName']]);
        } else {
            Caisse::findOrFail($this->editingCaisseId)->update(['name' => $validated['caisseName']]);
        }

        unset($this->caisseRows);
        $statusMessage = $this->editingCaisseId === null
            ? 'La caisse « '.$validated['caisseName'].' » a été créée.'
            : 'La caisse « '.$validated['caisseName'].' » a été modifiée.';
        $this->closeCaisseModal();
        $this->flashStatusMessage = $statusMessage;
    }

    /* ================= supprimer ================= */

    public function askToDeleteCaisse(int $caisseId): void
    {
        $this->deletingCaisseId = $caisseId;
        $this->showDeleteCaisseModal = true;
    }

    public function closeDeleteCaisseModal(): void
    {
        $this->showDeleteCaisseModal = false;
        $this->deletingCaisseId = null;
    }

    public function deleteCaisseLabel(): ?string
    {
        return $this->caisseNameForId($this->deletingCaisseId);
    }

    public function confirmDeleteCaisse(): void
    {
        $this->resetFlashMessages();
        $caisseId = $this->deletingCaisseId;
        $this->closeDeleteCaisseModal();

        if ($caisseId === null) {
            return;
        }

        if (! $this->ensurePermittedOrFlash(PermissionName::ManageCaisse)) {
            return;
        }

        $caisse = Caisse::findOrFail($caisseId);

        if ($caisse->isSystemCaisse()) {
            $this->flashErrorMessage = "Impossible de supprimer la caisse « {$caisse->name} » : c'est une caisse système utilisée par le routage automatique des paiements.";

            return;
        }

        if ($caisse->balance !== 0) {
            $this->flashErrorMessage = "Impossible de supprimer la caisse « {$caisse->name} » : son solde doit être à zéro avant suppression.";

            return;
        }

        $caisseName = $caisse->name;
        $caisse->delete();

        unset($this->caisseRows);
        $this->flashStatusMessage = 'La caisse « '.$caisseName.' » a été supprimée.';
    }

    /* ================= clôturer / réouvrir la journée ================= */

    public function toggleCaisseDayLock(int $caisseId): void
    {
        $this->resetFlashMessages();

        if (! $this->ensurePermittedOrFlash(PermissionName::ManageCaisse)) {
            return;
        }

        $caisse = Caisse::findOrFail($caisseId);

        if ($caisse->isLockedForToday()) {
            $caisse->update(['locked_until' => null]);
            $this->flashStatusMessage = 'La caisse « '.$caisse->name.' » a été réouverte.';
        } else {
            $caisse->update(['locked_until' => now()->toDateString()]);
            $this->flashStatusMessage = 'La journée a été clôturée pour « '.$caisse->name.' ».';
        }

        unset($this->caisseRows);
    }

    /* ================= entrée de caisse ================= */

    public function openEntreeDeCaisse(int $caisseId): void
    {
        $this->resetFlashMessages();
        $this->entreeCaisseId = $caisseId;
        $this->entreeAccountId = null;
        $this->entreeAmount = null;
        $this->entreeLabel = '';
        $this->resetValidation();
        $this->showEntreeModal = true;
    }

    public function closeEntreeModal(): void
    {
        $this->showEntreeModal = false;
        $this->entreeCaisseId = null;
    }

    public function saveEntreeDeCaisse(AccountService $accountService): void
    {
        $this->resetFlashMessages();

        if (! $this->ensurePermittedOrFlash(PermissionName::ManageCaisse)) {
            return;
        }

        if ($this->entreeCaisseId === null) {
            return;
        }

        $validated = $this->validate([
            'entreeAccountId' => ['required', 'integer', 'exists:accounts,id'],
            'entreeAmount' => ['required', 'integer', 'min:1'],
            'entreeLabel' => ['required', 'string', 'max:255'],
        ], attributes: [
            'entreeAccountId' => 'compte',
            'entreeAmount' => 'montant',
            'entreeLabel' => 'libellé',
        ]);

        $caisse = Caisse::findOrFail($this->entreeCaisseId);
        $account = Account::findOrFail($validated['entreeAccountId']);

        try {
            $accountService->processEntreeDeCaisse($caisse, $account, $validated['entreeAmount'], $validated['entreeLabel']);
            $this->closeEntreeModal();
            unset($this->caisseRows);
            $this->flashStatusMessage = 'Entrée de caisse enregistrée avec succès.';
        } catch (\Throwable $exception) {
            $this->flashErrorMessage = $exception->getMessage();
        }
    }

    /* ================= sortie de caisse ================= */

    public function openSortieDeCaisse(int $caisseId): void
    {
        $this->resetFlashMessages();
        $this->sortieCaisseId = $caisseId;
        $this->sortieAmount = null;
        $this->sortieLabel = '';
        $this->sortieAccountIds = [];
        $this->resetValidation();
        $this->showSortieModal = true;
    }

    public function closeSortieModal(): void
    {
        $this->showSortieModal = false;
        $this->sortieCaisseId = null;
    }

    public function saveSortieDeCaisse(AccountService $accountService): void
    {
        $this->resetFlashMessages();

        if (! $this->ensurePermittedOrFlash(PermissionName::ManageCaisse)) {
            return;
        }

        if ($this->sortieCaisseId === null) {
            return;
        }

        $validated = $this->validate([
            'sortieAmount' => ['required', 'integer', 'min:1'],
            'sortieLabel' => ['required', 'string', 'max:255'],
            'sortieAccountIds' => ['required', 'array', 'min:1'],
            'sortieAccountIds.*' => ['integer', 'exists:accounts,id'],
        ], attributes: [
            'sortieAmount' => 'montant',
            'sortieLabel' => 'libellé',
            'sortieAccountIds' => 'comptes à débiter',
        ]);

        $caisse = Caisse::findOrFail($this->sortieCaisseId);

        if ($caisse->balance < $validated['sortieAmount']) {
            $this->flashErrorMessage = "Le solde de la caisse « {$caisse->name} » ({$caisse->balance} F) est insuffisant pour cette sortie de {$validated['sortieAmount']} F.";

            return;
        }

        $totalSelectedBalance = Account::whereIn('id', $validated['sortieAccountIds'])->sum('balance');

        if ($totalSelectedBalance < $validated['sortieAmount']) {
            $this->flashErrorMessage = "Le solde total des comptes sélectionnés ({$totalSelectedBalance} F) est insuffisant pour couvrir le montant de la sortie ({$validated['sortieAmount']} F).";

            return;
        }

        try {
            $accountService->processSortieDeCaisse($caisse, $validated['sortieAmount'], $validated['sortieLabel'], $validated['sortieAccountIds']);
            $this->closeSortieModal();
            unset($this->caisseRows);
            $this->flashStatusMessage = 'Sortie de caisse enregistrée avec succès.';
        } catch (\Throwable $exception) {
            $this->flashErrorMessage = $exception->getMessage();
        }
    }

    /* ================= transférer vers une autre caisse ================= */

    public function openCaisseTransfer(int $caisseId): void
    {
        $this->resetFlashMessages();
        $this->transferFromCaisseId = $caisseId;
        $this->transferToCaisseId = null;
        $this->transferAmount = null;
        $this->transferDescription = null;
        $this->resetValidation();
        $this->showCaisseTransferModal = true;
    }

    public function closeCaisseTransferModal(): void
    {
        $this->showCaisseTransferModal = false;
        $this->transferFromCaisseId = null;
    }

    public function saveCaisseTransfer(): void
    {
        $this->resetFlashMessages();

        if (! $this->ensurePermittedOrFlash(PermissionName::ManageCaisse)) {
            return;
        }

        if ($this->transferFromCaisseId === null) {
            return;
        }

        $validated = $this->validate([
            'transferToCaisseId' => ['required', 'integer', 'different:transferFromCaisseId', 'exists:caisses,id'],
            'transferAmount' => ['required', 'integer', 'min:1'],
            'transferDescription' => ['nullable', 'string', 'max:255'],
        ], attributes: [
            'transferToCaisseId' => 'caisse destination',
            'transferAmount' => 'montant',
        ], messages: [
            'transferToCaisseId.different' => 'Les caisses source et destination doivent être différentes.',
        ]);

        try {
            DB::transaction(function () use ($validated): void {
                $fromCaisse = Caisse::findOrFail($this->transferFromCaisseId);
                $toCaisse = Caisse::findOrFail($validated['transferToCaisseId']);

                if ($fromCaisse->balance < $validated['transferAmount']) {
                    throw new \RuntimeException('Solde insuffisant dans la caisse source.');
                }

                $descriptionSuffix = $validated['transferDescription'] ? ' - '.$validated['transferDescription'] : '';
                $userId = auth()->id();

                $fromCaisse->transactions()->create([
                    'amount' => $validated['transferAmount'],
                    'label' => 'Transfert vers '.$toCaisse->name.$descriptionSuffix,
                    'transaction_type' => CaisseTransactionType::Withdraw,
                    'user_id' => $userId,
                ]);
                $fromCaisse->updateBalanceFromLedger();

                $toCaisse->transactions()->create([
                    'amount' => $validated['transferAmount'],
                    'label' => 'Transfert depuis '.$fromCaisse->name.$descriptionSuffix,
                    'transaction_type' => CaisseTransactionType::Deposit,
                    'user_id' => $userId,
                ]);
                $toCaisse->updateBalanceFromLedger();
            });

            $this->closeCaisseTransferModal();
            unset($this->caisseRows);
            $this->flashStatusMessage = 'Transfert effectué avec succès.';
        } catch (\Throwable $exception) {
            $this->flashErrorMessage = $exception->getMessage();
        }
    }

    /* ================= transactions (modale paresseuse) ================= */

    public function openCaisseTransactions(int $caisseId): void
    {
        $this->viewingCaisseTransactionsId = $caisseId;
        $this->showCaisseTransactionsModal = true;
    }

    public function closeCaisseTransactionsModal(): void
    {
        $this->showCaisseTransactionsModal = false;
        $this->viewingCaisseTransactionsId = null;
    }

    /**
     * @return array<int, array{id: int, amount: int, effectiveAmount: int, label: string|null, transactionType: string, createdAt: string}>
     */
    #[Computed]
    public function caisseTransactionsForModal(): array
    {
        if ($this->viewingCaisseTransactionsId === null) {
            return [];
        }

        return Caisse::findOrFail($this->viewingCaisseTransactionsId)
            ->transactions()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($transaction): array => [
                'id' => $transaction->id,
                'amount' => $transaction->amount,
                'effectiveAmount' => $transaction->effective_amount,
                'label' => $transaction->label,
                'transactionType' => $transaction->transaction_type->value,
                'createdAt' => $transaction->created_at->format('d/m/Y H:i'),
            ])
            ->all();
    }
}
