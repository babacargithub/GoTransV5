<?php

namespace App\Livewire\BackOffice;

use App\Http\Controllers\DepartController;
use App\Models\Depart;
use App\Models\Horaire;
use App\Models\Trajet;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Back office "Modifier départ" page for a single départ.
 *
 * Mirrors the legacy Vue admin edit screen (DepartureEdit.vue + DepartForm.vue in
 * "isEditing" mode) but fixes its long-standing bug: the legacy form hid the date
 * field in edit mode, so only the departure time could actually be changed. Here
 * both the departure date and time are editable and recombined into the single
 * `date` column before saving.
 *
 * On submit the component reuses the untouched DepartController@update business
 * logic through the shared controller and inspects its JsonResponse.
 */
#[Layout('components.layouts.back-office')]
class EditDepart extends Component
{
    public Depart $depart;

    public string $departName = '';

    /**
     * Departure date, formatted as Y-m-d for the native date input.
     */
    public string $departureDate = '';

    /**
     * Departure time, formatted as H:i for the native time input.
     */
    public string $departureTime = '';

    public ?int $horaireId = null;

    public int $visibility = Depart::VISIBILITE_ALL_CUSTOMERS;

    public ?string $errorMessage = null;

    public function mount(Depart $depart): void
    {
        $this->depart = $depart;

        $this->departName = (string) $depart->getRawOriginal('name');
        $this->departureDate = $depart->date->format('Y-m-d');
        $this->departureTime = $depart->date->format('H:i');
        $this->horaireId = $depart->horaire_id;
        $this->visibility = (int) ($depart->visibilite ?? Depart::VISIBILITE_ALL_CUSTOMERS);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'departName' => ['required', 'string', 'max:255'],
            'departureDate' => ['required', 'date'],
            'departureTime' => ['required', 'date_format:H:i'],
            'horaireId' => ['required', 'integer', Rule::exists('horaires', 'id')],
            'visibility' => ['required', 'integer', Rule::in(array_keys($this->visibilityOptions))],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'departName' => 'nom du départ',
            'departureDate' => 'date de départ',
            'departureTime' => 'heure de départ',
            'horaireId' => 'horaire',
            'visibility' => 'visibilité',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'departureTime.date_format' => "L'heure de départ doit être au format HH:MM.",
        ];
    }

    /**
     * Trajet name shown read-only for context; the legacy edit form exposed a trajet
     * picker but DepartController@update never persisted it, so it stays informational.
     */
    #[Computed]
    public function trajetName(): string
    {
        return Trajet::findOrFail($this->depart->trajet_id)->name;
    }

    /**
     * Horaire dropdown options keyed by id.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function horaireOptions(): array
    {
        return Horaire::query()
            ->orderBy('name')
            ->pluck('name', 'id')
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

    public function save(): void
    {
        $this->errorMessage = null;

        $this->validate();

        $combinedDepartureDateTime = Carbon::parse($this->departureDate)
            ->setTimeFromTimeString($this->departureTime)
            ->format('Y-m-d H:i:s');

        request()->merge([
            'name' => $this->departName,
            'date' => $combinedDepartureDateTime,
            'horaire_id' => $this->horaireId,
            'visibilite' => $this->visibility,
        ]);

        try {
            $legacyResponse = app(DepartController::class)->update(request(), $this->depart);
        } catch (\Throwable $exception) {
            $this->errorMessage = $exception->getMessage();

            return;
        }

        if ($legacyResponse->getStatusCode() === SymfonyResponse::HTTP_OK) {
            session()->flash('status', 'Le départ « '.$this->departName.' » a été modifié.');

            $this->redirectRoute('back-office.departs.index', navigate: true);

            return;
        }

        $this->errorMessage = data_get($legacyResponse->getData(true), 'message', "Le départ n'a pas pu être modifié.");
    }

    public function render(): View
    {
        return view('livewire.back-office.edit-depart')
            ->title('Modifier départ — '.$this->depart->identifier(with_trajet_prefix: true));
    }
}
