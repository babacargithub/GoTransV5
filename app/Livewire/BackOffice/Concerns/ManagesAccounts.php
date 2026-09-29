<?php

namespace App\Livewire\BackOffice\Concerns;

use App\Enums\AccountType;
use App\Enums\PermissionName;
use App\Models\Account;
use App\Services\AccountService;
use Livewire\Attributes\Computed;

/**
 * "Comptes" tab of the back-office Finance page: account CRUD, transfer
 * between accounts, and a lazy filterable transactions modal.
 *
 * Requires the host to provide $flashStatusMessage / $flashErrorMessage,
 * resetFlashMessages() and ensurePermittedOrFlash() — see CaisseBalancesPage.
 */
trait ManagesAccounts
{
    public bool $showAccountModal = false;

    public ?int $editingAccountId = null;

    public string $accountName = '';

    public string $accountType = '';

    public bool $accountIsActive = true;

    public bool $showDeleteAccountModal = false;

    public ?int $deletingAccountId = null;

    public bool $showAccountTransferModal = false;

    public ?int $transferFromAccountId = null;

    public ?int $transferToAccountId = null;

    public ?int $accountTransferAmount = null;

    public string $accountTransferLabel = '';

    public bool $showAccountTransactionsModal = false;

    public ?int $viewingAccountTransactionsId = null;

    public ?string $accountTransactionsDateFrom = null;

    public ?string $accountTransactionsDateTo = null;

    public ?string $accountTransactionsType = null;

    /**
     * @return array<int, array{id: int, name: string, accountType: string, accountTypeLabel: string, balance: int, isActive: bool}>
     */
    #[Computed]
    public function accountRows(): array
    {
        return Account::query()
            ->orderBy('account_type')
            ->orderBy('name')
            ->get()
            ->map(fn (Account $account): array => [
                'id' => $account->id,
                'name' => $account->name,
                'accountType' => $account->account_type->value,
                'accountTypeLabel' => $account->account_type->label(),
                'balance' => $account->balance,
                'isActive' => (bool) $account->is_active,
            ])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function accountTypeOptions(): array
    {
        return collect(AccountType::cases())
            ->mapWithKeys(fn (AccountType $type): array => [$type->value => $type->label()])
            ->all();
    }

    public function accountNameForId(?int $accountId): ?string
    {
        return $accountId === null ? null : Account::find($accountId)?->name;
    }

    /* ================= créer / modifier ================= */

    public function openCreateAccount(): void
    {
        $this->resetAccountForm();
        $this->editingAccountId = null;
        $this->showAccountModal = true;
    }

    public function openEditAccount(int $accountId): void
    {
        $account = Account::findOrFail($accountId);

        $this->editingAccountId = $account->id;
        $this->accountName = $account->name;
        $this->accountType = $account->account_type->value;
        $this->accountIsActive = (bool) $account->is_active;
        $this->resetValidation();
        $this->showAccountModal = true;
    }

    public function closeAccountModal(): void
    {
        $this->showAccountModal = false;
        $this->editingAccountId = null;
        $this->resetValidation();
    }

    private function resetAccountForm(): void
    {
        $this->accountName = '';
        $this->accountType = AccountType::Management->value;
        $this->accountIsActive = true;
        $this->resetValidation();
    }

    public function saveAccount(AccountService $accountService): void
    {
        $this->resetFlashMessages();

        if (! $this->ensurePermittedOrFlash(PermissionName::ManageAccounts)) {
            return;
        }

        $validated = $this->validate([
            'accountName' => ['required', 'string', 'max:255'],
            'accountType' => ['required', 'string', 'in:'.implode(',', array_keys($this->accountTypeOptions))],
            'accountIsActive' => ['boolean'],
        ], attributes: ['accountName' => 'nom', 'accountType' => 'type']);

        if ($this->editingAccountId === null) {
            $accountService->createAccount([
                'name' => $validated['accountName'],
                'account_type' => $validated['accountType'],
                'is_active' => $this->accountIsActive,
            ]);
            $statusMessage = 'Le compte « '.$validated['accountName'].' » a été créé.';
        } else {
            $account = Account::findOrFail($this->editingAccountId);
            $accountService->updateAccount($account, [
                'name' => $validated['accountName'],
                'is_active' => $this->accountIsActive,
            ]);
            $statusMessage = 'Le compte « '.$validated['accountName'].' » a été modifié.';
        }

        unset($this->accountRows);
        $this->closeAccountModal();
        $this->flashStatusMessage = $statusMessage;
    }

    /* ================= supprimer ================= */

    public function askToDeleteAccount(int $accountId): void
    {
        $this->deletingAccountId = $accountId;
        $this->showDeleteAccountModal = true;
    }

    public function closeDeleteAccountModal(): void
    {
        $this->showDeleteAccountModal = false;
        $this->deletingAccountId = null;
    }

    public function deleteAccountLabel(): ?string
    {
        return $this->accountNameForId($this->deletingAccountId);
    }

    public function confirmDeleteAccount(AccountService $accountService): void
    {
        $this->resetFlashMessages();
        $accountId = $this->deletingAccountId;
        $this->closeDeleteAccountModal();

        if ($accountId === null) {
            return;
        }

        if (! $this->ensurePermittedOrFlash(PermissionName::ManageAccounts)) {
            return;
        }

        $account = Account::findOrFail($accountId);
        $accountName = $account->name;

        try {
            $accountService->deleteAccount($account);
        } catch (\RuntimeException $exception) {
            $this->flashErrorMessage = $exception->getMessage();

            return;
        }

        unset($this->accountRows);
        $this->flashStatusMessage = 'Le compte « '.$accountName.' » a été supprimé.';
    }

    /* ================= transférer entre comptes ================= */

    public function openAccountTransfer(int $accountId): void
    {
        $this->resetFlashMessages();
        $this->transferFromAccountId = $accountId;
        $this->transferToAccountId = null;
        $this->accountTransferAmount = null;
        $this->accountTransferLabel = '';
        $this->resetValidation();
        $this->showAccountTransferModal = true;
    }

    public function closeAccountTransferModal(): void
    {
        $this->showAccountTransferModal = false;
        $this->transferFromAccountId = null;
    }

    public function saveAccountTransfer(AccountService $accountService): void
    {
        $this->resetFlashMessages();

        if (! $this->ensurePermittedOrFlash(PermissionName::ManageAccounts)) {
            return;
        }

        if ($this->transferFromAccountId === null) {
            return;
        }

        $validated = $this->validate([
            'transferToAccountId' => ['required', 'integer', 'different:transferFromAccountId', 'exists:accounts,id'],
            'accountTransferAmount' => ['required', 'integer', 'min:1'],
            'accountTransferLabel' => ['required', 'string', 'max:255'],
        ], attributes: [
            'transferToAccountId' => 'compte destination',
            'accountTransferAmount' => 'montant',
            'accountTransferLabel' => 'libellé',
        ], messages: [
            'transferToAccountId.different' => 'Les comptes source et destination doivent être différents.',
        ]);

        $fromAccount = Account::findOrFail($this->transferFromAccountId);
        $toAccount = Account::findOrFail($validated['transferToAccountId']);

        try {
            $accountService->transferBetweenAccounts(
                fromAccount: $fromAccount,
                toAccount: $toAccount,
                amount: $validated['accountTransferAmount'],
                label: $validated['accountTransferLabel'],
            );

            $this->closeAccountTransferModal();
            unset($this->accountRows);
            $this->flashStatusMessage = 'Transfert entre comptes effectué avec succès.';
        } catch (\Throwable $exception) {
            $this->flashErrorMessage = $exception->getMessage();
        }
    }

    /* ================= transactions (modale paresseuse, filtrable) ================= */

    public function openAccountTransactions(int $accountId): void
    {
        $this->viewingAccountTransactionsId = $accountId;
        $this->accountTransactionsDateFrom = null;
        $this->accountTransactionsDateTo = null;
        $this->accountTransactionsType = null;
        $this->showAccountTransactionsModal = true;
    }

    public function closeAccountTransactionsModal(): void
    {
        $this->showAccountTransactionsModal = false;
        $this->viewingAccountTransactionsId = null;
    }

    public function applyAccountTransactionsFilter(): void
    {
        unset($this->accountTransactionsForModal);
    }

    /**
     * @return array{transactions: array<int, array{id: int, amount: int, effectiveAmount: int, label: string|null, transactionType: string, category: string, categoryLabel: string, categoryColor: string, createdAt: string}>, totalDeposits: int, totalWithdrawals: int}
     */
    #[Computed]
    public function accountTransactionsForModal(): array
    {
        if ($this->viewingAccountTransactionsId === null) {
            return ['transactions' => [], 'totalDeposits' => 0, 'totalWithdrawals' => 0];
        }

        $account = Account::findOrFail($this->viewingAccountTransactionsId);

        $result = app(AccountService::class)->getAccountTransactions($account, [
            'date_from' => $this->accountTransactionsDateFrom,
            'date_to' => $this->accountTransactionsDateTo,
            'type' => $this->accountTransactionsType,
        ]);

        return [
            'transactions' => $result->transactions
                ->map(fn ($transaction): array => [
                    'id' => $transaction->id,
                    'amount' => $transaction->amount,
                    'effectiveAmount' => $transaction->effective_amount,
                    'label' => $transaction->label,
                    'transactionType' => $transaction->transaction_type->value,
                    'category' => $transaction->category->value,
                    'categoryLabel' => $transaction->category->label(),
                    'categoryColor' => $transaction->category->badgeColor(),
                    'createdAt' => $transaction->created_at->format('d/m/Y H:i'),
                ])
                ->all(),
            'totalDeposits' => $result->totalDeposits,
            'totalWithdrawals' => $result->totalWithdrawals,
        ];
    }
}
