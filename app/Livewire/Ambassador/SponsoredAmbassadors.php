<?php

namespace App\Livewire\Ambassador;

use App\Livewire\Ambassador\Concerns\ResolvesLoggedInAmbassador;
use App\Models\AppParams;
use App\Services\AmbassadorPerformanceService;
use App\Services\AmbassadorService;
use App\Services\NotificationService;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

/**
 * The ambassadors sponsored by the logged-in ambassador, with their progress towards the sponsorship bonus,
 * and the form to invite a new one: the invited person gets an SMS with a link to accept.
 */
#[Layout('components.layouts.ambassador')]
class SponsoredAmbassadors extends Component
{
    use ResolvesLoggedInAmbassador;
    use WithPagination;

    public const MAX_INVITATIONS_PER_HOUR = 10;

    /** Mirrors the mobile app's client-side phone check (utils/phone_number_validator.js). */
    private const SENEGAL_MOBILE_REGEX = '/^7[7680][0-9]{7}$/';

    public string $firstName = '';

    public string $lastName = '';

    public string $phoneNumber = '';

    public string $waveNumber = '';

    public ?string $invitedFirstName = null;

    public ?string $formError = null;

    #[Computed]
    public function sponsoredAmbassadors(): LengthAwarePaginator
    {
        return app(AmbassadorPerformanceService::class)->paginateSponsoredAmbassadorsWithProgress($this->ambassador);
    }

    #[Computed]
    public function sponsorshipThreshold(): int
    {
        return (AppParams::query()->first() ?? new AppParams(['data' => []]))->getAmbassadorSponsorshipThreshold();
    }

    #[Computed]
    public function sponsorshipBonusAmount(): int
    {
        return (AppParams::query()->first() ?? new AppParams(['data' => []]))->getAmbassadorSponsorshipBonusAmount();
    }

    public function inviteAmbassador(AmbassadorService $ambassadorService, NotificationService $notificationService): void
    {
        $this->formError = null;
        $this->invitedFirstName = null;

        $rateLimiterKey = 'ambassador-invitation:'.$this->ambassador->id;

        if (RateLimiter::tooManyAttempts($rateLimiterKey, self::MAX_INVITATIONS_PER_HOUR)) {
            $this->formError = 'Trop d\'invitations envoyées. Réessayez plus tard.';

            return;
        }

        $validated = $this->validate([
            'firstName' => ['required', 'string', 'max:100'],
            'lastName' => ['required', 'string', 'max:100'],
            'phoneNumber' => ['required', 'regex:'.self::SENEGAL_MOBILE_REGEX],
            'waveNumber' => ['nullable', 'regex:'.self::SENEGAL_MOBILE_REGEX],
        ], [
            'phoneNumber.regex' => 'Numéro invalide (ex : 77 123 45 67).',
            'waveNumber.regex' => 'Numéro invalide (ex : 77 123 45 67).',
        ], [
            'firstName' => 'prénom',
            'lastName' => 'nom',
            'phoneNumber' => 'numéro de téléphone',
            'waveNumber' => 'numéro Wave',
        ]);

        RateLimiter::hit($rateLimiterKey, 3600);

        try {
            $invitedAmbassador = $ambassadorService->inviteSponsoredAmbassador($this->ambassador, [
                'first_name' => trim($validated['firstName']),
                'last_name' => trim($validated['lastName']),
                'phone_number' => $validated['phoneNumber'],
                'wave_phone_number' => $validated['waveNumber'] ?: null,
            ]);
        } catch (DomainException $domainException) {
            $this->formError = $domainException->getMessage();

            return;
        }

        try {
            $notificationService->notifyAmbassadorOfSponsorshipInvitation($invitedAmbassador);
        } catch (Throwable $notificationFailure) {
            report($notificationFailure);
            $this->formError = "L'invitation est enregistrée mais le SMS n'a pas pu être envoyé. Contactez-nous pour le renvoyer.";

            return;
        }

        $this->invitedFirstName = $invitedAmbassador->first_name;
        $this->reset('firstName', 'lastName', 'phoneNumber', 'waveNumber');
    }

    public function render(): View
    {
        return view('livewire.ambassador.sponsored-ambassadors')->title('Mon équipe — Globe One Transport');
    }
}
