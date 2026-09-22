<?php

namespace App\Livewire\BackOffice;

use App\Http\Controllers\BusController;
use App\Http\Controllers\DepartController;
use App\Models\Depart;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Back office "Ajouter un bus" page for a single départ.
 *
 * Mirrors the legacy Vue admin modal: the same fields, the same dropdowns fed by
 * the backend (BusController@vehicules gives both the vehicules and the
 * itinéraires; the visibilité options are the Depart::VISIBILITE_* constants).
 * On submit the component reuses the untouched BusController@addBusToDepart
 * business logic through the shared controller and inspects its JsonResponse.
 */
#[Layout('components.layouts.back-office')]
class AddBusToDepart extends Component
{
    public Depart $depart;

    public ?int $vehiculeId = null;

    public string $name = '';

    public ?int $numberOfSeats = null;

    public ?float $ticketPrice = null;

    public ?float $gpTicketPrice = null;

    public ?string $convoyorPhoneNumbers = null;

    public ?int $itineraryId = null;

    public int $visibility = Depart::VISIBILITE_ALL_CUSTOMERS;

    public ?string $errorMessage = null;

    public function mount(Depart $depart): void
    {
        $this->depart = $depart;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'vehiculeId' => ['required', 'integer', Rule::exists('vehicules', 'id')],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('buses', 'name')->where(fn ($query) => $query->where('depart_id', $this->depart->id)),
            ],
            'numberOfSeats' => ['required', 'integer', 'min:1'],
            'ticketPrice' => ['required', 'numeric', 'min:0'],
            'gpTicketPrice' => ['nullable', 'numeric', 'min:0'],
            'convoyorPhoneNumbers' => ['nullable', 'string', 'max:255'],
            'itineraryId' => ['nullable', 'integer', Rule::exists('itineraries', 'id')],
            'visibility' => ['required', 'integer', Rule::in(array_keys($this->visibilityOptions))],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'vehiculeId' => 'véhicule transport',
            'name' => 'nom du bus',
            'numberOfSeats' => 'nombre de place',
            'ticketPrice' => 'prix ticket',
            'gpTicketPrice' => 'prix ticket GP',
            'convoyorPhoneNumbers' => 'numéro convoyeur',
            'itineraryId' => 'itinéraire',
            'visibility' => 'visibilité',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'name.unique' => 'Un bus avec ce nom existe déjà pour ce départ.',
        ];
    }

    /**
     * The raw {vehicules, itineraries} payload from the shared BusController@vehicules endpoint.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function backendDropdownData(): array
    {
        return app(BusController::class)->vehicules()->getData(true);
    }

    /**
     * Vehicule dropdown options, straight from the shared BusController@vehicules payload.
     *
     * @return array<int, array{id: int, name: string, numberOfSeats: int, ticketPrice: float}>
     */
    #[Computed]
    public function vehiculeOptions(): array
    {
        return collect($this->backendDropdownData['vehicules'] ?? [])
            ->map(fn (array $vehicule): array => [
                'id' => (int) $vehicule['id'],
                'name' => $vehicule['name'],
                'numberOfSeats' => (int) $vehicule['nombre_place'],
                'ticketPrice' => (float) $vehicule['ticket_price'],
            ])
            ->values()
            ->all();
    }

    /**
     * Itinéraire dropdown options, straight from the shared BusController@vehicules payload.
     *
     * @return array<int, array{id: int, name: string}>
     */
    #[Computed]
    public function itineraryOptions(): array
    {
        return collect($this->backendDropdownData['itineraries'] ?? [])
            ->map(fn (array $itinerary): array => [
                'id' => (int) $itinerary['id'],
                'name' => $itinerary['name'],
            ])
            ->values()
            ->all();
    }

    /**
     * Visibilité dropdown options keyed by the Depart::VISIBILITE_* value stored on the bus.
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

    /**
     * Selecting a vehicule pre-fills the seat count and ticket price the same way the
     * legacy modal does, so the operator only overrides them when they differ.
     */
    public function updatedVehiculeId(mixed $value): void
    {
        $selectedVehicule = Arr::first(
            $this->vehiculeOptions,
            fn (array $vehicule): bool => $vehicule['id'] === (int) $value,
        );

        if ($selectedVehicule === null) {
            return;
        }

        $this->numberOfSeats = $selectedVehicule['numberOfSeats'];
        $this->ticketPrice = $selectedVehicule['ticketPrice'];
    }

    public function save(): void
    {
        $this->errorMessage = null;

        $this->validate();

        request()->merge([
            'name' => $this->name,
            'ticket_price' => $this->ticketPrice,
            'nombre_place' => $this->numberOfSeats,
            'vehicule_id' => $this->vehiculeId,
            'gp_ticket_price' => $this->gpTicketPrice,
            'itinerary_id' => $this->itineraryId,
            'agent_numbers' => $this->convoyorPhoneNumbers,
            'visibilite' => $this->visibility,
        ]);

        try {
            $legacyResponse = app(DepartController::class)->addBusToDepart($this->depart, request());
        } catch (\Throwable $exception) {
            $this->errorMessage = $exception->getMessage();

            return;
        }

        if ($legacyResponse->getStatusCode() === SymfonyResponse::HTTP_CREATED) {
            session()->flash('status', 'Le bus « '.$this->name.' » a été ajouté au départ.');

            $this->redirectRoute('back-office.departs.index', navigate: true);

            return;
        }

        $this->errorMessage = data_get($legacyResponse->getData(true), 'message', "Le bus n'a pas pu être créé.");
    }

    public function render(): View
    {
        return view('livewire.back-office.add-bus-to-depart')
            ->title('Ajouter un bus — '.$this->depart->identifier(with_trajet_prefix: true));
    }
}
