<?php

namespace App\Livewire\Website;

use App\Enums\AmbassadorStatus;
use App\Models\Ambassador;
use App\Services\AmbassadorService;
use App\Services\NotificationService;
use DomainException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Throwable;

/**
 * Page reached through the link a sponsoring ambassador sent to the person they invite. Accepting only moves
 * the ambassador to "pending approval": the sponsor and the administrators are told, then the back office
 * approves (or rejects) them.
 */
class AmbassadorInvitation extends Component
{
    public string $invitationToken = '';

    public bool $invitationWasAccepted = false;

    public ?string $formError = null;

    public function mount(string $invitationToken): void
    {
        $this->invitationToken = $invitationToken;
    }

    /**
     * The invited ambassador, only while the invitation is pending and its sponsor is still active.
     */
    #[Computed]
    public function invitedAmbassador(): ?Ambassador
    {
        $invitedAmbassador = app(AmbassadorService::class)->findInvitedAmbassadorByToken($this->invitationToken);

        return $invitedAmbassador?->sponsor?->status === AmbassadorStatus::Active ? $invitedAmbassador : null;
    }

    public function acceptInvitation(AmbassadorService $ambassadorService, NotificationService $notificationService): void
    {
        $this->formError = null;

        try {
            $acceptedAmbassador = $ambassadorService->acceptInvitation($this->invitationToken);
        } catch (DomainException $domainException) {
            $this->formError = $domainException->getMessage();

            return;
        }

        $this->invitationWasAccepted = true;

        // The acceptance is already recorded: a failing SMS must never make the invited person think it was not.
        foreach ([
            fn () => $notificationService->notifySponsorOfAcceptedInvitation($acceptedAmbassador),
            fn () => $notificationService->notifyAdministratorsOfAmbassadorAwaitingApproval($acceptedAmbassador),
        ] as $sendNotification) {
            try {
                $sendNotification();
            } catch (Throwable $notificationFailure) {
                report($notificationFailure);
            }
        }
    }

    public function render(): View
    {
        return view('livewire.website.ambassador-invitation')
            ->layout('components.layouts.website', [
                'title' => 'Invitation ambassadeur | Globe One Transport',
                'description' => 'Rejoignez le programme ambassadeur de Globe One Transport.',
                'robots' => 'noindex, follow',
            ]);
    }
}
