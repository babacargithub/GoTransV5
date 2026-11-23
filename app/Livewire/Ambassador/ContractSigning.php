<?php

namespace App\Livewire\Ambassador;

use App\Livewire\Ambassador\Concerns\ResolvesLoggedInAmbassador;
use App\Models\AmbassadorContractSignature;
use App\Models\AmbassadorProgramDocument;
use App\Services\AmbassadorContractSigningService;
use App\Services\NotificationService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * Ambassador space "Contrat" page. An ambassador who has not signed reads the latest contract, accepts it, types their
 * full name and confirms with the code received by SMS. One who signed sees the version they signed, which later
 * versions never replace.
 */
#[Layout('components.layouts.ambassador')]
class ContractSigning extends Component
{
    use ResolvesLoggedInAmbassador;

    public bool $acceptsContract = false;

    public string $typedFullName = '';

    public string $signingCode = '';

    #[Locked]
    public bool $signingCodeWasSent = false;

    /**
     * The contract version shown when the page opened: signing refuses another one, so an edit made while the
     * ambassador was reading can never be signed unseen.
     */
    #[Locked]
    public ?int $readContractId = null;

    public function mount(): void
    {
        $this->readContractId = $this->currentContract?->id;
    }

    /**
     * Runs on every request after the first: when a newer contract version was published while an unsigned
     * ambassador had the page open, the page switches to it and drops the acceptance, name and code given for the
     * old text, so they only ever sign the latest version they have read.
     */
    public function hydrate(): void
    {
        if ($this->ambassador->hasSignedContract()) {
            return;
        }

        $latestContractId = app(AmbassadorContractSigningService::class)->currentContract()?->id;

        if ($latestContractId === $this->readContractId) {
            return;
        }

        $this->readContractId = $latestContractId;
        $this->reset(['acceptsContract', 'typedFullName', 'signingCode', 'signingCodeWasSent']);
        $this->resetErrorBag();

        session()->flash('error', 'Le contrat a été mis à jour. Relisez la nouvelle version avant de la signer.');
    }

    #[Computed]
    public function currentContract(): ?AmbassadorProgramDocument
    {
        return app(AmbassadorContractSigningService::class)->currentContract();
    }

    /**
     * The signature this ambassador gave, if they signed.
     */
    #[Computed]
    public function signature(): ?AmbassadorContractSignature
    {
        return $this->ambassador->signedContract();
    }

    /**
     * The text shown: the signed snapshot for an ambassador who signed, the latest contract for one who did not.
     */
    #[Computed]
    public function shownContractText(): string
    {
        return $this->signature?->content_snapshot ?? $this->currentContract?->content ?? '';
    }

    #[Computed]
    public function contractHtml(): string
    {
        return Str::markdown($this->shownContractText, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }

    public function sendSigningCode(): void
    {
        $this->resetErrorBag();

        $this->validate([
            'acceptsContract' => ['accepted'],
            'typedFullName' => ['required', 'string', 'max:150'],
        ], [
            'acceptsContract.accepted' => 'Cochez la case pour accepter le contrat.',
            'typedFullName.required' => 'Saisissez votre nom complet.',
        ]);

        $signingService = app(AmbassadorContractSigningService::class);

        if (! $signingService->typedNameMatchesAmbassador($this->ambassador, $this->typedFullName)) {
            $this->addError('typedFullName', 'Le nom saisi ne correspond pas à votre nom complet : '.$this->ambassador->full_name);

            return;
        }

        try {
            $signingService->sendSigningCode($this->ambassador);
        } catch (DomainException $refusal) {
            $this->addError('signingCode', $refusal->getMessage());

            return;
        }

        $this->signingCodeWasSent = true;
        $this->signingCode = '';
        session()->flash('status', 'Un code vous a été envoyé par SMS.');
    }

    /**
     * Back to the first step (to fix the typed name): the code that was sent stays valid until it expires.
     */
    public function restartSigning(): void
    {
        $this->reset(['signingCode', 'signingCodeWasSent']);
        $this->resetErrorBag();
    }

    public function signContract(): void
    {
        $this->resetErrorBag();

        $this->validate([
            'acceptsContract' => ['accepted'],
            'typedFullName' => ['required', 'string', 'max:150'],
            'signingCode' => ['required', 'digits:6'],
        ], [
            'acceptsContract.accepted' => 'Cochez la case pour accepter le contrat.',
            'typedFullName.required' => 'Saisissez votre nom complet.',
            'signingCode.required' => 'Saisissez le code reçu par SMS.',
            'signingCode.digits' => 'Le code contient 6 chiffres.',
        ]);

        try {
            app(AmbassadorContractSigningService::class)->sign(
                $this->ambassador,
                (int) $this->readContractId,
                $this->typedFullName,
                $this->signingCode,
                request()->ip(),
                request()->userAgent(),
            );
        } catch (DomainException $refusal) {
            $this->addError('signingCode', $refusal->getMessage());

            return;
        } catch (Throwable $unexpectedFailure) {
            report($unexpectedFailure);
            $this->addError('signingCode', 'La signature a échoué. Réessayez dans un instant.');

            return;
        }

        $this->reset(['acceptsContract', 'typedFullName', 'signingCode', 'signingCodeWasSent']);
        unset($this->signature);

        // The signature is stored: a failing SMS to the administrators must never make it look like it failed.
        try {
            app(NotificationService::class)->notifyAdministratorsOfSignedAmbassadorContract($this->ambassador);
        } catch (Throwable $notificationFailure) {
            report($notificationFailure);
        }

        session()->flash('status', 'Votre contrat est signé. Merci !');
    }

    public function render(): View
    {
        return view('livewire.ambassador.contract-signing')->title('Mon contrat — Globe One Transport');
    }
}
