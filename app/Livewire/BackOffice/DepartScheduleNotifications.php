<?php

namespace App\Livewire\BackOffice;

use App\Models\Depart;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Back office "Envoi des rendez-vous" page for a single départ.
 *
 * First iteration: just the message composer. The legacy Vue admin also picks
 * recipients (paid/unpaid, bus, point de départ, destination) and sends the
 * templated SMS booking by booking through
 * BookingController@sendScheduleNotification — that flow is involved enough to
 * warrant its own session, so it is intentionally left out here.
 */
#[Layout('components.layouts.back-office')]
class DepartScheduleNotifications extends Component
{
    public Depart $depart;

    public string $message = '';

    /**
     * Tokens the legacy sender swaps per booking before sending the SMS.
     *
     * @var array<int, array{token: string, description: string}>
     */
    public array $availablePlaceholders = [
        ['token' => '<Client>', 'description' => 'Prénom du client'],
        ['token' => '<Depart>', 'description' => 'Nom du départ'],
        ['token' => '<PointDep>', 'description' => 'Point de départ du client'],
        ['token' => '<Arret>', 'description' => 'Point de rendez-vous (arrêt)'],
        ['token' => '<Heure>', 'description' => 'Heure de rendez-vous'],
        ['token' => '<Wave>', 'description' => 'Lien de paiement Wave'],
        ['token' => '<OM>', 'description' => 'Lien de paiement Orange Money'],
    ];

    public function mount(Depart $depart): void
    {
        $this->depart = $depart;
        $this->message = $this->defaultMessageTemplate();
    }

    public function departLabel(): string
    {
        return $this->depart->identifier(with_trajet_prefix: true);
    }

    private function defaultMessageTemplate(): string
    {
        return implode("\n", [
            'Bonjour <Client>',
            'Pour départ <Depart> :',
            'RV <PointDep>, à <Heure>, <Arret>',
        ]);
    }

    public function render(): View
    {
        return view('livewire.back-office.depart-schedule-notifications')
            ->title('Envoi des rendez-vous — '.$this->departLabel());
    }
}
