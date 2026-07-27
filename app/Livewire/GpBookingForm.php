<?php

namespace App\Livewire;

use App\Models\Trajet;
use Livewire\Component;

class GpBookingForm extends Component
{
    public $trajets = [];
    public $selectedTrajet = null;

    public function mount()
    {
        $this->trajets = Trajet::all();
    }

    public function selectTrajet($trajetId)
    {
        $this->selectedTrajet = $trajetId;
    }

    public function handleVoyager()
    {
        if (!$this->selectedTrajet) {
            session()->flash('error', 'Veuillez sélectionner un trajet');
            return;
        }

        // TODO: Implement booking flow
        session()->flash('message', 'Vous serez redirigé vers le formulaire de réservation bientôt');
    }

    public function render()
    {
        return view('livewire.gp-booking-form');
    }
}
