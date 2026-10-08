<?php

namespace App\Livewire\BackOffice;

use App\Http\Controllers\OrangeMoneyController;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Back office "Paiements OM" page.
 *
 * Reads the live Orange Money merchant balance and recent transactions through
 * the untouched OrangeMoneyController, and lets an operator trigger a withdrawal
 * (cash-in) through OrangeMoneyController@withdraw. Every provider call is caught
 * so an unreachable OM API only degrades the page rather than breaking it.
 */
#[Layout('components.layouts.back-office')]
class OrangeMoneyPage extends Component
{
    public string $transactionSearch = '';

    public bool $showWithdrawModal = false;

    public ?int $withdrawAmount = null;

    public ?string $withdrawPhoneNumber = null;

    public ?string $withdrawSecretCode = null;

    public ?string $withdrawErrorMessage = null;

    /**
     * Live Orange Money merchant balance, or null when the OM API is unreachable.
     */
    #[Computed]
    public function orangeMoneyBalance(): ?int
    {
        try {
            $balanceResponse = app(OrangeMoneyController::class)->balance();

            return (int) data_get($balanceResponse->getData(true), 'balance');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Recent Orange Money transactions, or null when the OM API is unreachable.
     *
     * @return array<int, array<string, mixed>>|null
     */
    #[Computed]
    public function orangeMoneyTransactions(): ?array
    {
        try {
            $transactionsResponse = app(OrangeMoneyController::class)->transactions();
            $payload = $transactionsResponse->getData(true);

            return data_get($payload, 'content', is_array($payload) ? $payload : []);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Transactions narrowed by the search box: matches the Orange transactionId (MP...),
     * our own globesoft reference, or the customer phone number (digits only, so
     * "77 123 45 67" finds "221771234567"). Null when the OM API is unreachable.
     *
     * @return array<int, array<string, mixed>>|null
     */
    #[Computed]
    public function filteredOrangeMoneyTransactions(): ?array
    {
        $orangeMoneyTransactions = $this->orangeMoneyTransactions;
        $searchTerm = mb_strtolower(trim($this->transactionSearch));

        if ($orangeMoneyTransactions === null || $searchTerm === '') {
            return $orangeMoneyTransactions;
        }

        $searchDigits = preg_replace('/\D+/', '', $searchTerm);

        return array_values(array_filter(
            $orangeMoneyTransactions,
            function (array $orangeMoneyTransaction) use ($searchTerm, $searchDigits): bool {
                $matchesIdentifier = collect([
                    data_get($orangeMoneyTransaction, 'transactionId'),
                    data_get($orangeMoneyTransaction, 'reference'),
                ])->contains(fn ($identifier) => is_string($identifier) && str_contains(mb_strtolower($identifier), $searchTerm));

                $customerPhoneDigits = preg_replace('/\D+/', '', (string) data_get($orangeMoneyTransaction, 'customer.id', ''));
                $matchesPhoneNumber = $searchDigits !== '' && str_contains($customerPhoneDigits, $searchDigits);

                return $matchesIdentifier || $matchesPhoneNumber;
            }
        ));
    }

    public function openWithdrawModal(): void
    {
        $this->withdrawAmount = null;
        $this->withdrawPhoneNumber = null;
        $this->withdrawSecretCode = null;
        $this->withdrawErrorMessage = null;
        $this->resetValidation();
        $this->showWithdrawModal = true;
    }

    public function closeWithdrawModal(): void
    {
        $this->showWithdrawModal = false;
        $this->withdrawErrorMessage = null;
        $this->resetValidation();
    }

    /**
     * Trigger a cash-in through OrangeMoneyController@withdraw.
     */
    public function confirmWithdraw(): void
    {
        $this->withdrawErrorMessage = null;

        $this->validate([
            'withdrawAmount' => ['required', 'integer', 'min:1'],
            'withdrawPhoneNumber' => ['required', 'string', 'max:20'],
            'withdrawSecretCode' => ['required', 'string'],
        ], attributes: [
            'withdrawAmount' => 'montant',
            'withdrawPhoneNumber' => 'numéro de téléphone',
            'withdrawSecretCode' => 'code secret',
        ]);

        request()->merge([
            'amount' => $this->withdrawAmount,
            'phoneNumber' => $this->withdrawPhoneNumber,
            'secretCode' => $this->withdrawSecretCode,
        ]);

        try {
            $withdrawResponse = app(OrangeMoneyController::class)->withdraw(request());
        } catch (\Throwable $exception) {
            $this->withdrawErrorMessage = $exception->getMessage();

            return;
        }

        if ($withdrawResponse->getStatusCode() !== SymfonyResponse::HTTP_OK) {
            $this->withdrawErrorMessage = data_get($withdrawResponse->getData(true), 'message', "Le retrait n'a pas pu être effectué.");

            return;
        }

        unset($this->orangeMoneyBalance, $this->orangeMoneyTransactions, $this->filteredOrangeMoneyTransactions);
        $this->closeWithdrawModal();
        session()->flash('status', 'Le retrait de '.number_format((int) $this->withdrawAmount, 0, ',', ' ').' FCFA a été envoyé.');
    }

    public function render(): View
    {
        return view('livewire.back-office.orange-money-page')->title('Paiements OM — Back Office');
    }
}
