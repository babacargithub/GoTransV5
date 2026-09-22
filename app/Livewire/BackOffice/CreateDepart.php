<?php

namespace App\Livewire\BackOffice;

use App\Http\Controllers\DepartController;
use App\Models\Depart;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Back office "Nouveau départ" page.
 *
 * Mirrors the legacy Vue admin modal (DepartureNew.vue + DepartForm.vue) minus the
 * removed "Créer plusieurs départs" checkbox: the page always creates one départ per
 * checked date. Dropdown options come from the shared
 * DepartController@getDataForDepartCreation endpoint, and on submit the component
 * reuses the untouched DepartController@store business logic through the shared
 * controller and inspects its JsonResponse.
 */
#[Layout('components.layouts.back-office')]
class CreateDepart extends Component
{
    /**
     * How many days ahead the operator can schedule départs, matching the legacy 60-day window.
     */
    private const DEPARTURE_DATE_WINDOW_IN_DAYS = 60;

    public ?int $trajetId = null;

    public ?int $horaireId = null;

    public int $visibility = Depart::VISIBILITE_ALL_CUSTOMERS;

    public string $busTypeToCreate = 'simple';

    public ?int $vehiculeId = null;

    public string $busName = 'Bus';

    public ?int $numberOfSeats = 57;

    public ?float $ticketPrice = 3550;

    public ?float $grandPublicTicketPrice = 6000;

    public string $departNameTemplate = 'Départ <Date>';

    /**
     * @var array<int, string> Checked departure dates, each formatted as Y-m-d.
     */
    public array $selectedDepartureDates = [];

    public ?string $errorMessage = null;

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'trajetId' => ['required', 'integer', Rule::exists('trajets', 'id')],
            'horaireId' => ['required', 'integer', Rule::exists('horaires', 'id')],
            'visibility' => ['required', 'integer', Rule::in(array_keys($this->visibilityOptions))],
            'busTypeToCreate' => ['required', Rule::in(array_keys($this->busTypeOptions))],
            'vehiculeId' => ['required', 'integer', Rule::exists('vehicules', 'id')],
            'busName' => ['required', 'string', 'max:255'],
            'numberOfSeats' => ['required', 'integer', 'min:1'],
            'ticketPrice' => ['required', 'numeric', 'min:0'],
            'grandPublicTicketPrice' => ['required', 'numeric', 'min:0'],
            'departNameTemplate' => ['required', 'string', 'max:255'],
            'selectedDepartureDates' => ['required', 'array', 'min:1'],
            'selectedDepartureDates.*' => ['date', Rule::in(array_keys($this->upcomingDepartureDates))],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'trajetId' => 'trajet',
            'horaireId' => 'horaire',
            'visibility' => 'visibilité',
            'busTypeToCreate' => 'type de bus',
            'vehiculeId' => 'véhicule transport',
            'busName' => 'nom du bus',
            'numberOfSeats' => 'nombre de place',
            'ticketPrice' => 'prix ticket',
            'grandPublicTicketPrice' => 'prix grand public',
            'departNameTemplate' => 'template',
            'selectedDepartureDates' => 'dates',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'selectedDepartureDates.required' => 'Cochez au moins une date de départ.',
            'selectedDepartureDates.min' => 'Cochez au moins une date de départ.',
        ];
    }

    /**
     * The raw {events, trajets, horaires, vehicules} payload from the shared
     * DepartController@getDataForDepartCreation endpoint.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function backendDropdownData(): array
    {
        return app(DepartController::class)->getDataForDepartCreation()->getData(true);
    }

    /**
     * Trajet dropdown options keyed by id.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function trajetOptions(): array
    {
        return collect($this->backendDropdownData['trajets'] ?? [])
            ->mapWithKeys(fn (array $trajet): array => [(int) $trajet['id'] => $trajet['name']])
            ->all();
    }

    /**
     * Horaire dropdown options keyed by id.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function horaireOptions(): array
    {
        return collect($this->backendDropdownData['horaires'] ?? [])
            ->mapWithKeys(fn (array $horaire): array => [(int) $horaire['id'] => $horaire['name']])
            ->all();
    }

    /**
     * Véhicule dropdown options keyed by id, with the seat count and whether it is air-conditioned.
     *
     * @return array<int, array{name: string, numberOfSeats: int, isAirConditioned: bool}>
     */
    #[Computed]
    public function vehiculeOptions(): array
    {
        return collect($this->backendDropdownData['vehicules'] ?? [])
            ->mapWithKeys(fn (array $vehicule): array => [
                (int) $vehicule['id'] => [
                    'name' => $vehicule['name'],
                    'numberOfSeats' => (int) $vehicule['nombre_place'],
                    'isAirConditioned' => (int) $vehicule['vehicule_type'] === 2,
                ],
            ])
            ->all();
    }

    /**
     * Visibilité dropdown options keyed by the Depart::VISIBILITE_* value.
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
     * Bus type radio options, matching the legacy `type_of_bus_to_create` values.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function busTypeOptions(): array
    {
        return [
            'simple' => 'Bus simple seulement',
            'climatise' => 'Bus climatisé seulement',
            'both' => 'Les 2',
        ];
    }

    /**
     * The checkable departure dates (today through the 60-day window), keyed by Y-m-d,
     * each value being the French label shown next to the checkbox.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function upcomingDepartureDates(): array
    {
        $dates = [];

        for ($dayOffset = 0; $dayOffset <= self::DEPARTURE_DATE_WINDOW_IN_DAYS; $dayOffset++) {
            $departureDate = Carbon::today()->addDays($dayOffset);
            $dates[$departureDate->toDateString()] = $departureDate->locale('fr')->translatedFormat('l j F Y');
        }

        return $dates;
    }

    /**
     * The checkable departure dates grouped by their French month heading, for display.
     *
     * @return array<string, array<string, string>>
     */
    #[Computed]
    public function upcomingDepartureDatesByMonth(): array
    {
        $datesByMonth = [];

        foreach ($this->upcomingDepartureDates as $isoDate => $label) {
            $monthHeading = Carbon::parse($isoDate)->locale('fr')->translatedFormat('F Y');
            $datesByMonth[$monthHeading][$isoDate] = $label;
        }

        return $datesByMonth;
    }

    /**
     * Selecting a véhicule pre-fills the seat count the same way the legacy modal does,
     * so the operator only overrides it when it differs.
     */
    public function updatedVehiculeId(mixed $value): void
    {
        $selectedVehicule = Arr::get($this->vehiculeOptions, (int) $value);

        if ($selectedVehicule === null) {
            return;
        }

        $this->numberOfSeats = $selectedVehicule['numberOfSeats'];
    }

    /**
     * Builds a départ name from the template by replacing `<Date>` with the French
     * weekday + day + month of the departure date, mirroring the legacy moment() format.
     */
    private function buildDepartName(string $departureDate): string
    {
        $frenchDateLabel = Carbon::parse($departureDate)->locale('fr')->translatedFormat('l d F');

        return str_replace('<Date>', $frenchDateLabel, $this->departNameTemplate);
    }

    public function save(): void
    {
        $this->errorMessage = null;

        $this->validate();

        $departsPayload = collect($this->selectedDepartureDates)
            ->sort()
            ->values()
            ->map(fn (string $departureDate): array => [
                'name' => $this->buildDepartName($departureDate),
                'date' => $departureDate,
                'trajet_id' => $this->trajetId,
                'type_of_bus_to_create' => $this->busTypeToCreate,
                'visibilite' => $this->visibility,
                'horaire_id' => $this->horaireId,
                'bus' => [
                    'name' => $this->busName,
                    'ticket_price' => $this->ticketPrice,
                    'gp_ticket_price' => $this->grandPublicTicketPrice,
                    'nombre_place' => $this->numberOfSeats,
                    'vehicule_id' => $this->vehiculeId,
                ],
            ])
            ->all();

        request()->merge(['departs' => $departsPayload]);

        try {
            $legacyResponse = app(DepartController::class)->store(request());
        } catch (\Throwable $exception) {
            $this->errorMessage = $exception->getMessage();

            return;
        }

        if ($legacyResponse->getStatusCode() === SymfonyResponse::HTTP_CREATED) {
            $createdDepartsCount = count($departsPayload);

            session()->flash('status', $createdDepartsCount > 1
                ? $createdDepartsCount.' départs ont été créés.'
                : 'Le départ a été créé.');

            $this->redirectRoute('back-office.departs.index', navigate: true);

            return;
        }

        $this->errorMessage = data_get($legacyResponse->getData(true), 'message', "Le départ n'a pas pu être créé.");
    }

    public function render(): View
    {
        return view('livewire.back-office.create-depart')->title('Nouveau départ');
    }
}
