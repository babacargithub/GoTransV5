<?php

namespace App\Livewire\Website;

use App\Services\AmbassadorService;
use App\Services\NotificationService;
use DomainException;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Throwable;

/**
 * Page reached through the link a sponsoring ambassador sent to the person they invite. Opening the link accepts
 * the invitation, which only moves the ambassador to "pending approval": the sponsor and the administrators are
 * told, then the back office approves (or rejects) them.
 */
class AmbassadorInvitation extends Component
{
    public string $invitationToken = '';

    public bool $invitationWasAccepted = false;

    public function mount(string $invitationToken, AmbassadorService $ambassadorService, NotificationService $notificationService): void
    {
        $this->invitationToken = $invitationToken;

        $this->acceptInvitation($ambassadorService, $notificationService);
    }

    private function acceptInvitation(AmbassadorService $ambassadorService, NotificationService $notificationService): void
    {
        try {
            $acceptedAmbassador = $ambassadorService->acceptInvitation($this->invitationToken);
        } catch (DomainException) {
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
