<?php

namespace App\Livewire\BackOffice;

use App\Http\Controllers\BusController;
use App\Models\Bus;
use App\Models\Depart;
use App\Models\Itinerary;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Back office "Modifier infos bus" page for a single bus.
 *
 * Mirrors the legacy Vue admin edit screen (EditBus.vue + BusForm.vue): the
 * editable fields are exactly the ones BusController@update whitelists (name,
 * nombre_place, ticket_price, gp_ticket_price, visibilite, itinerary_id,
 * agent_numbers). The véhicule is shown read-only for context — the legacy form
 * exposed a véhicule picker but update() never persisted it.
 *
 * On submit the component reuses the untouched BusController@update business
 * logic through the shared controller and inspects its JsonResponse.
 */
#[Layout('components.layouts.back-office')]
class EditBus extends Component
{
    public Bus $bus;

    public string $busName = '';

    public ?int $numberOfSeats = null;

    public ?float $ticketPrice = null;

    public ?float $gpTicketPrice = null;

    public ?string $agentNumbers = null;

    public ?int $itineraryId = null;

    public int $visibility = Depart::VISIBILITE_ALL_CUSTOMERS;

    public ?string $errorMessage = null;

    public function mount(Bus $bus): void
    {
        $this->bus = $bus;

        $this->busName = (string) $bus->name;
        $this->numberOfSeats = (int) $bus->nombre_place;
        $this->ticketPrice = $bus->ticket_price !== null ? (float) $bus->ticket_price : null;
        $this->gpTicketPrice = $bus->gp_ticket_price !== null ? (float) $bus->gp_ticket_price : null;
        $this->agentNumbers = $bus->agent_numbers;
        $this->itineraryId = $bus->itinerary_id;
        $this->visibility = (int) ($bus->visibilite ?? Depart::VISIBILITE_ALL_CUSTOMERS);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'busName' => ['required', 'string', 'max:255'],
            'numberOfSeats' => ['required', 'integer', 'min:1'],
            'ticketPrice' => ['required', 'numeric', 'min:0'],
            'gpTicketPrice' => ['required', 'numeric', 'min:0'],
            'agentNumbers' => ['nullable', 'string', 'max:255'],
            'itineraryId' => ['required', 'integer', Rule::exists('itineraries', 'id')],
            'visibility' => ['required', 'integer', Rule::in(array_keys($this->visibilityOptions))],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'busName' => 'nom du bus',
            'numberOfSeats' => 'nombre de places',
            'ticketPrice' => 'prix du ticket',
            'gpTicketPrice' => 'prix du ticket GP',
            'agentNumbers' => 'numéro du convoyeur',
            'itineraryId' => 'itinéraire',
            'visibility' => 'visibilité',
        ];
    }

    /**
     * Départ shown read-only for context.
     */
    #[Computed]
    public function departLabel(): string
    {
        return $this->bus->depart->identifier(with_trajet_prefix: true);
    }

    #[Computed]
    public function vehiculeName(): ?string
    {
        return $this->bus->vehicule?->name;
    }

    /**
     * Itinéraire dropdown options keyed by id.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function itineraryOptions(): array
    {
        return Itinerary::query()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Visibilité dropdown options keyed by the Depart::VISIBILITE_* value (buses
     * share the same visibility scale as départs).
     *
     * @return array<int, string>
     */
    #[Computed]
    public function visibilityOptions(): array
    {
        return [
            Depart::VISIBILITE_ALL_CUSTOMERS => 'Pour tous les clients',
            Depart::VISIBILITE_GP_CUSTOMERS_ONLY => 'Clients GP uniquement',
            Depart::VISIBILITE_ST_CUSTOMERS_ONLY => 'Clients ST uniquement',
            Depart::VISIBILITE_STAFF_ONLY => 'Personnel uniquement',
        ];
    }

    public function save(): void
    {
        $this->errorMessage = null;

        $this->validate();

        request()->merge([
            'name' => $this->busName,
            'nombre_place' => $this->numberOfSeats,
            'ticket_price' => $this->ticketPrice,
            'gp_ticket_price' => $this->gpTicketPrice,
            'agent_numbers' => $this->agentNumbers,
            'itinerary_id' => $this->itineraryId,
            'visibilite' => $this->visibility,
        ]);

        try {
            $legacyResponse = app(BusController::class)->update(request(), $this->bus);
        } catch (\Throwable $exception) {
            $this->errorMessage = $exception->getMessage();

            return;
        }

        if ($legacyResponse->getStatusCode() === SymfonyResponse::HTTP_OK) {
            session()->flash('status', 'Le bus « '.$this->busName.' » a été modifié.');

            $this->redirectRoute('back-office.departs.index', navigate: true);

            return;
        }

        $this->errorMessage = data_get($legacyResponse->getData(true), 'message', "Le bus n'a pas pu être modifié.");
    }

    public function render(): View
    {
        return view('livewire.back-office.edit-bus')
            ->title('Modifier infos bus — '.$this->bus->name);
    }
}
