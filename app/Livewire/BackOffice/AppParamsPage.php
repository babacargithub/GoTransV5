<?php

namespace App\Livewire\BackOffice;

use App\Models\AppParams;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Back office "Paramètres" page.
 *
 * The whole app configuration lives in a single app_params row whose `data`
 * column is a JSON object (consumed by the mobile app via
 * MobileAppController@params). This page edits the known scalar keys and, on
 * save, merges them back into `data` with array_replace_recursive — exactly what
 * the legacy MobileAppController@updateParams does — so unrelated keys (e.g. the
 * derived `trajets` list) are preserved.
 */
#[Layout('components.layouts.back-office')]
class AppParamsPage extends Component
{
    public string $appName = '';

    public string $appDescription = '';

    public string $apiEndpoint = '';

    public string $frontEndpoint = '';

    public string $serverEndpoint = '';

    public string $minimumVersion = '';

    public string $mainCustomerServiceNumber = '';

    public string $secondCustomerServiceNumber = '';

    public string $thirdCustomerServiceNumber = '';

    public string $busAgentDefaultNumber = '';

    public ?int $discountPrice = null;

    public string $discountCondition = '';

    public string $warningMessageBeforeBooking = '';

    /**
     * The keys this page owns, mapped from the `data` JSON key to the component
     * property. Everything not listed here is left untouched on save.
     *
     * @var array<string, string>
     */
    private array $editableParamKeys = [
        'app_name' => 'appName',
        'app_description' => 'appDescription',
        'api_endpoint' => 'apiEndpoint',
        'front_endpoint' => 'frontEndpoint',
        'server_endpoint' => 'serverEndpoint',
        'minimum_version' => 'minimumVersion',
        'main_customer_service_number' => 'mainCustomerServiceNumber',
        'second_customer_service_number' => 'secondCustomerServiceNumber',
        'third_customer_service_number' => 'thirdCustomerServiceNumber',
        'bus_agent_default_number' => 'busAgentDefaultNumber',
        'discount_price' => 'discountPrice',
        'discount_condition' => 'discountCondition',
        'warning_message_before_booking' => 'warningMessageBeforeBooking',
    ];

    public function mount(): void
    {
        $data = AppParams::query()->first()?->data ?? [];

        foreach ($this->editableParamKeys as $dataKey => $propertyName) {
            $value = $data[$dataKey] ?? null;

            $this->{$propertyName} = $propertyName === 'discountPrice'
                ? ($value !== null ? (int) $value : null)
                : (string) ($value ?? '');
        }
    }

    /**
     * Merge the edited keys back into app_params.data, leaving every other key
     * untouched (same behaviour as MobileAppController@updateParams).
     */
    public function save(): void
    {
        $validated = $this->validate([
            'appName' => ['required', 'string', 'max:255'],
            'appDescription' => ['nullable', 'string', 'max:2000'],
            'apiEndpoint' => ['nullable', 'string', 'max:255', 'url'],
            'frontEndpoint' => ['nullable', 'string', 'max:255', 'url'],
            'serverEndpoint' => ['nullable', 'string', 'max:255', 'url'],
            'minimumVersion' => ['nullable', 'string', 'max:20'],
            'mainCustomerServiceNumber' => ['nullable', 'string', 'max:20'],
            'secondCustomerServiceNumber' => ['nullable', 'string', 'max:20'],
            'thirdCustomerServiceNumber' => ['nullable', 'string', 'max:20'],
            'busAgentDefaultNumber' => ['nullable', 'string', 'max:20'],
            'discountPrice' => ['nullable', 'integer', 'min:0'],
            'discountCondition' => ['nullable', 'string', 'max:255'],
            'warningMessageBeforeBooking' => ['nullable', 'string', 'max:2000'],
        ], attributes: [
            'appName' => "nom de l'application",
            'appDescription' => "description de l'application",
            'apiEndpoint' => "URL de l'API",
            'frontEndpoint' => 'URL du site public',
            'serverEndpoint' => 'URL du serveur',
            'minimumVersion' => 'version minimale',
            'mainCustomerServiceNumber' => 'numéro du service client principal',
            'secondCustomerServiceNumber' => 'deuxième numéro du service client',
            'thirdCustomerServiceNumber' => 'troisième numéro du service client',
            'busAgentDefaultNumber' => 'numéro du convoyeur par défaut',
            'discountPrice' => 'montant de la réduction',
            'discountCondition' => 'condition de la réduction',
            'warningMessageBeforeBooking' => "message d'avertissement avant réservation",
        ]);

        $editedParams = [];
        foreach ($this->editableParamKeys as $dataKey => $propertyName) {
            $editedParams[$dataKey] = $validated[$propertyName];
        }

        $appParams = AppParams::query()->first() ?? new AppParams(['data' => []]);
        $appParams->data = array_replace_recursive($appParams->data ?? [], $editedParams);
        $appParams->save();

        session()->flash('status', 'Les paramètres ont été enregistrés.');
    }

    public function render(): View
    {
        return view('livewire.back-office.app-params-page')->title('Paramètres — Back Office');
    }
}
