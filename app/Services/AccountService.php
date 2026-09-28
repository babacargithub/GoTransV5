<?php

namespace App\Services;

use App\Data\Account\AccountTransactionsResultDTO;
use App\Enums\AccountTransactionType;
use App\Enums\AccountType;
use App\Enums\CaisseTransactionType;
use App\Exceptions\InsufficientAccountBalanceException;
use App\Exceptions\InvariantException;
use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Caisse;
use App\Models\CaisseTransaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Generic chart-of-accounts ledger, paired with the Caisse (physical till) ledger.
 *
 * Financial invariant: SUM(account.balance) == SUM(caisse.balance) must hold before
 * and after every operation exposed here. Every paired caisse+account operation runs
 * inside a DB::transaction and ends with assertGlobalInvariantHolds() so a violation
 * rolls the whole operation back rather than persisting inconsistent balances — this
 * matters in particular for RecordTicketPaymentInCaisse, a queued job that may retry.
 *
 * Every transaction row records the initiating user_id. Callers running inside an
 * authenticated back-office request may omit it (it defaults to auth()->id()); a
 * queued job has no session, so it must resolve and pass the actor explicitly at
 * dispatch time (null for a fully-automatic transaction with no staff actor, e.g.
 * a caisse deposit triggered by an online Wave/OM payment webhook).
 */
class AccountService
{
    // ── Display / management ─────────────────────────────────────────────────

    /**
     * Return all accounts, ready for display.
     */
    public function getAllAccountsForDisplay(): Collection
    {
        return Account::query()
            ->orderBy('account_type')
            ->orderBy('name')
            ->get();
    }

    /**
     * Return transactions for an account, ordered newest-first.
     * Optionally filter by date range (date_from / date_to) and transaction type (CREDIT / DEBIT).
     */
    public function getAccountTransactions(Account $account, array $filters = []): AccountTransactionsResultDTO
    {
        $baseQuery = $account->transactions();

        if (! empty($filters['date_from'])) {
            $baseQuery->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $baseQuery->whereDate('created_at', '<=', $filters['date_to']);
        }

        if (! empty($filters['type']) && in_array($filters['type'], ['CREDIT', 'DEBIT'], strict: true)) {
            $baseQuery->where('transaction_type', $filters['type']);
        }

        $transactions = (clone $baseQuery)->orderByDesc('created_at')->get();

        $totalDeposits = (int) (clone $baseQuery)
            ->where('transaction_type', AccountTransactionType::Credit->value)
            ->sum('amount');

        $totalWithdrawals = (int) (clone $baseQuery)
            ->where('transaction_type', AccountTransactionType::Debit->value)
            ->sum('amount');

        return new AccountTransactionsResultDTO(
            transactions: $transactions,
            totalDeposits: $totalDeposits,
            totalWithdrawals: $totalWithdrawals,
        );
    }

    /**
     * Create a new account with the given attributes (balance defaults to 0).
     */
    public function createAccount(array $attributes): Account
    {
        return Account::create(array_merge(['balance' => 0], $attributes));
    }

    /**
     * Update the editable fields of an account (name and is_active only).
     * Balance can never be changed directly — use credit/debit instead.
     */
    public function updateAccount(Account $account, array $attributes): Account
    {
        $account->update($attributes);

        return $account;
    }

    /**
     * Delete an account.
     *
     * @throws \RuntimeException if the account balance is non-zero, to prevent orphaned funds
     */
    public function deleteAccount(Account $account): void
    {
        if ($account->balance !== 0) {
            throw new \RuntimeException(
                "Impossible de supprimer le compte «{$account->name}» : solde non nul ({$account->balance} F). "
                .'Videz le solde avant de supprimer ce compte.'
            );
        }

        $account->delete();
    }

    // ── Pure account operations (no caisse change) ──────────────────────────

    /**
     * Credit an account — money flows IN, balance increases.
     *
     * @throws InvalidArgumentException if amount is not positive
     */
    public function credit(
        Account $account,
        int $amount,
        string $label,
        string $referenceType = 'MANUAL',
        ?int $referenceId = null,
        ?int $userId = null,
    ): AccountTransaction {
        if ($amount <= 0) {
            throw new InvalidArgumentException(
                "Le montant à créditer doit être un entier strictement positif. Reçu : {$amount}."
            );
        }

        $accountTransaction = $account->transactions()->create([
            'amount' => $amount,
            'transaction_type' => AccountTransactionType::Credit,
            'label' => $label,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'user_id' => $userId ?? auth()->id(),
        ]);

        $account->updateBalanceFromLedger();

        return $accountTransaction;
    }

    /**
     * Debit an account — money flows OUT, balance decreases.
     *
     * @throws InvalidArgumentException if amount is not positive
     * @throws InsufficientAccountBalanceException if the debit would make balance negative
     */
    public function debit(
        Account $account,
        int $amount,
        string $label,
        string $referenceType = 'MANUAL',
        ?int $referenceId = null,
        ?int $userId = null,
    ): AccountTransaction {
        if ($amount <= 0) {
            throw new InvalidArgumentException(
                "Le montant à débiter doit être un entier strictement positif. Reçu : {$amount}."
            );
        }

        $account->refresh();

        if ($account->balance < $amount) {
            throw new InsufficientAccountBalanceException(
                "Solde insuffisant pour le compte «{$account->name}». "
                ."Disponible : {$account->balance} F — Requis : {$amount} F."
            );
        }

        $accountTransaction = $account->transactions()->create([
            'amount' => $amount,
            'transaction_type' => AccountTransactionType::Debit,
            'label' => $label,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'user_id' => $userId ?? auth()->id(),
        ]);

        $account->updateBalanceFromLedger();

        return $accountTransaction;
    }

    /**
     * Transfer an amount from one account to another without touching any caisse.
     *
     * @return array{debit: AccountTransaction, credit: AccountTransaction}
     *
     * @throws InsufficientAccountBalanceException if the source account has insufficient balance
     */
    public function transferBetweenAccounts(
        Account $fromAccount,
        Account $toAccount,
        int $amount,
        string $label,
        string $referenceType = 'MANUAL',
        ?int $referenceId = null,
        ?int $userId = null,
    ): array {
        $userId ??= auth()->id();

        return DB::transaction(function () use ($fromAccount, $toAccount, $amount, $label, $referenceType, $referenceId, $userId): array {
            $debitTransaction = $this->debit($fromAccount, $amount, $label, $referenceType, $referenceId, $userId);
            $creditTransaction = $this->credit($toAccount, $amount, $label, $referenceType, $referenceId, $userId);

            return [
                'debit' => $debitTransaction,
                'credit' => $creditTransaction,
            ];
        });
    }

    // ── Paired caisse + account operations ──────────────────────────────────

    /**
     * Deposit into a caisse AND simultaneously credit an account.
     *
     * These two operations MUST always be performed together to preserve the
     * company-wide invariant: SUM(account balances) == SUM(caisse balances).
     * Wrapped in DB::transaction so a retried caller (e.g. a queued job) never
     * leaves the two ledgers out of sync.
     *
     * @return array{caisse_transaction: CaisseTransaction, account_transaction: AccountTransaction}
     */
    public function depositToCaisseAndCreditAccount(
        Caisse $caisse,
        Account $account,
        int $amount,
        string $caisseLabel,
        string $accountLabel,
        string $referenceType = 'MANUAL',
        ?int $referenceId = null,
        ?int $userId = null,
    ): array {
        if ($amount <= 0) {
            throw new InvalidArgumentException(
                "Le montant doit être un entier strictement positif. Reçu : {$amount}."
            );
        }

        $userId ??= auth()->id();

        return DB::transaction(function () use ($caisse, $account, $amount, $caisseLabel, $accountLabel, $referenceType, $referenceId, $userId): array {
            $caisseTransaction = $caisse->transactions()->create([
                'amount' => $amount,
                'transaction_type' => CaisseTransactionType::Deposit,
                'label' => $caisseLabel,
                'user_id' => $userId,
            ]);
            $caisse->updateBalanceFromLedger();

            $accountTransaction = $this->credit($account, $amount, $accountLabel, $referenceType, $referenceId, $userId);

            $this->assertGlobalInvariantHolds();

            return [
                'caisse_transaction' => $caisseTransaction,
                'account_transaction' => $accountTransaction,
            ];
        });
    }

    /**
     * Withdraw from a caisse AND simultaneously debit an account.
     *
     * @return array{caisse_transaction: CaisseTransaction, account_transaction: AccountTransaction}
     *
     * @throws InsufficientAccountBalanceException if the account has insufficient balance
     */
    public function withdrawFromCaisseAndDebitAccount(
        Caisse $caisse,
        Account $account,
        int $amount,
        string $caisseLabel,
        string $accountLabel,
        string $referenceType = 'MANUAL',
        ?int $referenceId = null,
        ?int $userId = null,
    ): array {
        if ($amount <= 0) {
            throw new InvalidArgumentException(
                "Le montant doit être un entier strictement positif. Reçu : {$amount}."
            );
        }

        $userId ??= auth()->id();

        return DB::transaction(function () use ($caisse, $account, $amount, $caisseLabel, $accountLabel, $referenceType, $referenceId, $userId): array {
            $accountTransaction = $this->debit($account, $amount, $accountLabel, $referenceType, $referenceId, $userId);

            $caisseTransaction = $caisse->transactions()->create([
                'amount' => $amount,
                'transaction_type' => CaisseTransactionType::Withdraw,
                'label' => $caisseLabel,
                'user_id' => $userId,
            ]);
            $caisse->updateBalanceFromLedger();

            $this->assertGlobalInvariantHolds();

            return [
                'caisse_transaction' => $caisseTransaction,
                'account_transaction' => $accountTransaction,
            ];
        });
    }

    // ── Entrée de caisse ────────────────────────────────────────────────────

    /**
     * Process an "entrée de caisse" (cash-in): deposit into a caisse and credit
     * a single account.
     *
     * @throws InvalidArgumentException if amount is not positive
     */
    public function processEntreeDeCaisse(
        Caisse $caisse,
        Account $account,
        int $amount,
        string $label,
        ?int $userId = null,
    ): void {
        if ($amount <= 0) {
            throw new InvalidArgumentException(
                "Le montant de l'entrée de caisse doit être un entier strictement positif. Reçu : {$amount}."
            );
        }

        $this->depositToCaisseAndCreditAccount(
            caisse: $caisse,
            account: $account,
            amount: $amount,
            caisseLabel: $label,
            accountLabel: $label,
            referenceType: 'ENTREE_DE_CAISSE',
            userId: $userId,
        );
    }

    // ── Sortie de caisse ────────────────────────────────────────────────────

    /**
     * Process a "sortie de caisse" (cash-out): withdraw from a caisse and debit
     * a list of accounts sequentially until the full amount is covered.
     *
     * Accounts are debited in the given order, each up to its full balance, until
     * the amount is covered.
     *
     * @param  int[]  $orderedAccountIds  Account IDs to debit, in the order provided by the user
     *
     * @throws InvalidArgumentException if amount is not positive
     * @throws InsufficientAccountBalanceException|\Throwable if any individual account debit fails
     */
    public function processSortieDeCaisse(
        Caisse $caisse,
        int $amount,
        string $label,
        array $orderedAccountIds,
        ?int $userId = null,
    ): void {
        if ($amount <= 0) {
            throw new InvalidArgumentException(
                "Le montant de la sortie de caisse doit être un entier strictement positif. Reçu : {$amount}."
            );
        }

        $userId ??= auth()->id();

        DB::transaction(function () use ($caisse, $amount, $label, $orderedAccountIds, $userId): void {
            $caisse->transactions()->create([
                'amount' => $amount,
                'transaction_type' => CaisseTransactionType::Withdraw,
                'label' => $label,
                'user_id' => $userId,
            ]);
            $caisse->updateBalanceFromLedger();

            $remainingAmount = $amount;
            $accountsById = Account::whereIn('id', $orderedAccountIds)->get()->keyBy('id');

            foreach ($orderedAccountIds as $accountId) {
                if ($remainingAmount <= 0) {
                    break;
                }

                $account = $accountsById->get($accountId);

                if ($account === null) {
                    continue;
                }

                $amountToDebitFromThisAccount = min($account->balance, $remainingAmount);

                if ($amountToDebitFromThisAccount > 0) {
                    $this->debit($account, $amountToDebitFromThisAccount, $label, 'SORTIE_DE_CAISSE', null, $userId);
                    $remainingAmount -= $amountToDebitFromThisAccount;
                }
            }

            $this->assertGlobalInvariantHolds();
        });
    }

    // ── Invariant verification ───────────────────────────────────────────────

    public function computeTotalAccountBalance(): int
    {
        return (int) Account::sum('balance');
    }

    public function computeTotalCaisseBalance(): int
    {
        return (int) Caisse::sum('balance');
    }

    public function isGlobalInvariantSatisfied(): bool
    {
        return $this->computeTotalAccountBalance() === $this->computeTotalCaisseBalance();
    }

    /**
     * @throws InvariantException if SUM(account.balance) ≠ SUM(caisse.balance)
     */
    public function assertGlobalInvariantHolds(): void
    {
        $totalAccountBalance = $this->computeTotalAccountBalance();
        $totalCaisseBalance = $this->computeTotalCaisseBalance();

        if ($totalAccountBalance !== $totalCaisseBalance) {
            throw new InvariantException(
                "Violation de l'invariant comptable : la somme des comptes ({$totalAccountBalance} F) "
                ."ne correspond pas à la somme des caisses ({$totalCaisseBalance} F). "
                .'Opération annulée.'
            );
        }
    }

    // ── Account lookup / provisioning ───────────────────────────────────────

    /**
     * Returns the single company-wide TicketSales account, creating it if needed.
     */
    public function getOrCreateTicketSalesAccount(): Account
    {
        return Account::firstOrCreate(
            ['account_type' => AccountType::TicketSales->value],
            [
                'name' => AccountType::TicketSales->label(),
                'balance' => 0,
                'is_active' => true,
            ]
        );
    }
}
