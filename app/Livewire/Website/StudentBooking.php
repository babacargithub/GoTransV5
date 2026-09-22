<?php

namespace App\Livewire\Website;

use App\Http\Controllers\MobileAppController;
use App\Http\Requests\MobileMultipleBookingRequest;
use App\Http\Resources\MobileTrajetDepartsResource;
use App\Http\Resources\PaymentResponseResource;
use App\Models\AppParams;
use App\Models\Booking;
use App\Models\Depart;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Public-website "réservation étudiant" form.
 *
 * Students are the default customer segment of the public site; other segments (GP, staff…)
 * follow different business rules and get their own forms — hence the explicit name.
 *
 * The form itself is 100% front-end: it collects the passengers, then hands the exact same
 * payload the mobile app sends to the untouched MobileAppController@saveMultipleBookings
 * (via the MobileMultipleBookingRequest it type-hints), so every booking rule — customer
 * creation, bus assignment, "déjà réservé" checks, waiting list, payment initiation — is the
 * battle-tested backend logic, not a re-implementation.
 */
class StudentBooking extends Component
{
    public const MAX_PASSENGERS = 10;

    /** Mirrors the mobile app's client-side phone check (utils/phone_number_validator.js). */
    private const SENEGAL_MOBILE_REGEX = '/^7[7680][0-9]{7}$/';

    public Depart $depart;

    public ?int $busId = null;

    public ?int $passengersCount = null;

    /**
     * One row per passenger.
     *
     * @var array<int, array{full_name: string, phone_number: string, point_dep_id: int|string|null}>
     */
    public array $passengers = [];

    /** "wave" | "om" */
    public ?string $paymentMethod = null;

    /** Orange Money paying number — only required when $paymentMethod === "om". */
    public ?string $orangeMoneyNumber = null;

    public bool $showSummary = false;

    public bool $showNonRefundableWarning = false;

    public ?string $formError = null;

    public function mount(Depart $depart): void
    {
        $this->depart = $depart;
        $this->busId = request()->integer('bus_id') ?: null;
    }

    /**
     * The trajet's départs / point-departs / destinations, straight from the resource the
     * mobile API and the caravane page already use.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function trajetResource(): array
    {
        return (new MobileTrajetDepartsResource($this->depart->trajet))->resolve(request());
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    #[Computed]
    public function pointDepartOptions(): array
    {
        return collect($this->trajetResource['pointDeparts'] ?? [])
            ->map(fn ($pointDepart): array => [
                'id' => (int) $pointDepart['id'],
                'name' => (string) $pointDepart['name'],
            ])
            ->values()
            ->all();
    }

    #[Computed]
    public function guessedDestinationName(): ?string
    {
        // TODO: the destination is currently hard-coded to the trajet's first destination.
        // A later iteration must resolve it from each passenger's chosen drop-off point.
        return collect($this->trajetResource['destinations'] ?? [])->value('name');
    }

    #[Computed]
    public function ticketPrice(): float
    {
        if ($this->busId !== null) {
            $selectedBus = $this->depart->buses->firstWhere('id', $this->busId);

            if ($selectedBus !== null) {
                return (float) $selectedBus->ticket_price;
            }
        }

        return (float) ($this->depart->getBusForBooking()?->ticket_price ?? 0);
    }

    #[Computed]
    public function bookingIsOpen(): bool
    {
        return ! $this->depart->isPassed() && ! $this->depart->closed;
    }

    /**
     * The non-refundable warning shown when the customer clicks "Valider" on the summary.
     * Text (with {ticketPrice} / {payment_method} placeholders) is stored in app_params.
     */
    #[Computed]
    public function nonRefundableWarning(): string
    {
        $template = data_get(AppParams::first()?->data, 'warning_message_before_booking')
            ?? "N.B. Le ticket n'est pas remboursable !";

        return strtr($template, [
            '{ticketPrice}' => number_format($this->totalTicketPrice(), 0, ',', ' '),
            '{payment_method}' => $this->paymentMethodLabel(),
        ]);
    }

    public function updatedPassengersCount(mixed $value): void
    {
        $count = max(1, min(self::MAX_PASSENGERS, (int) $value));
        $previousRows = $this->passengers;

        $this->passengers = [];
        for ($index = 0; $index < $count; $index++) {
            $this->passengers[$index] = $previousRows[$index] ?? [
                'full_name' => '',
                'phone_number' => '',
                'point_dep_id' => null,
            ];
        }

        $this->resetBookingStepState();
        $this->resetErrorBag();
    }

    /**
     * Any edit to the form:
     *  - dismisses the summary / warning steps,
     *  - clears the (possibly stale) submission error for the edited field so a lingering
     *    message never misleads a customer who is already correcting their input,
     *  - re-runs the live validation for that field.
     */
    public function updated(string $name, mixed $value): void
    {
        $this->formError = null;
        $this->resetBookingStepState();

        if (! str_starts_with($name, 'passengers.')) {
            $this->resetErrorBag($name);

            return;
        }

        [, $rawIndex, $field] = array_pad(explode('.', $name), 3, null);
        $passengerIndex = (int) $rawIndex;

        $this->resetErrorBag($name);

        if ($field === 'phone_number') {
            $this->validatePassengerPhoneNumberLive($passengerIndex);
        } elseif ($field === 'full_name') {
            $this->validatePassengerFullNameLive($passengerIndex);
        }
    }

    /**
     * "Vérifier ma réservation" — validates every field, then opens the summary modal.
     */
    public function reviewBooking(): void
    {
        if (! $this->validateBookingForm()) {
            return;
        }

        $this->showSummary = true;
        $this->showNonRefundableWarning = false;
    }

    /**
     * "Valider" on the summary — reveals the non-refundable warning before the final confirmation.
     */
    public function acknowledgeSummary(): void
    {
        $this->showNonRefundableWarning = true;
    }

    public function closeSummary(): void
    {
        $this->resetBookingStepState();
    }

    /**
     * Final confirmation: hand the payload to the backend and route the customer to payment.
     */
    public function confirmBooking(): void
    {
        if (! $this->validateBookingForm()) {
            $this->resetBookingStepState();

            return;
        }

        // Close the summary/warning modal up front: from here on the outcome is either a redirect
        // to payment or a form-level error banner — the modal must never stay stuck on screen.
        $this->resetBookingStepState();

        try {
            $mobileRequest = $this->buildValidatedMobileBookingRequest();
        } catch (HttpResponseException $exception) {
            $this->formError = data_get($exception->getResponse()->getData(true), 'message')
                ?? "Votre réservation n'a pas pu être enregistrée.";

            return;
        } catch (ValidationException $exception) {
            $this->formError = $exception->validator->errors()->first()
                ?: 'Certaines informations des passagers sont invalides.';

            return;
        } catch (\Throwable $exception) {
            report($exception);
            $this->formError = "Votre réservation n'a pas pu être enregistrée. Veuillez vérifier les informations saisies.";

            return;
        }

        try {
            $paymentResponse = app(MobileAppController::class)->saveMultipleBookings($this->depart, $mobileRequest);
        } catch (\Throwable $exception) {
            report($exception);
            $this->formError = "Le paiement n'a pas pu être initié. Veuillez réessayer dans un instant.";
            $this->resetBookingStepState();

            return;
        }

        $responseData = $paymentResponse instanceof PaymentResponseResource
            ? $paymentResponse->data
            : (array) (method_exists($paymentResponse, 'getData') ? $paymentResponse->getData(true) : []);

        $groupId = data_get($responseData, 'group_id');

        if ($groupId === null || ($paymentResponse instanceof PaymentResponseResource && ! $paymentResponse->isOK())) {
            $this->formError = $this->paymentInitiationErrorMessage($paymentResponse, $responseData);
            $this->resetBookingStepState();

            return;
        }

        $bookingUuid = $this->tagGroupBookingsWithUuid((int) $groupId);

        if ($this->paymentMethod === 'wave') {
            $waveLaunchUrl = data_get($responseData, 'paymentResponse.wave_launch_url');

            if (filled($waveLaunchUrl)) {
                // Open the Wave checkout handler. The booking page (route below) is where the
                // customer lands afterwards to see the bookings / download the tickets.
                $this->redirect($waveLaunchUrl);

                return;
            }
        }

        session()->flash('status', $this->paymentMethod === 'om'
            ? "Le paiement Orange Money a été initié. Validez l'opération sur votre téléphone en tapant #144# puis votre code secret."
            : 'Votre réservation a été enregistrée. Procédez au paiement pour confirmer vos billets.');

        $this->redirectRoute('website.bookings.show', ['uuid' => $bookingUuid]);
    }

    public function render(): View
    {
        $routeLabel = $this->depart->trajet->public_name ?? $this->depart->trajet->name;

        return view('livewire.website.student-booking')
            ->layout('components.layouts.website', [
                'title' => 'Réserver — '.$routeLabel.' | Globe One Transport',
                'description' => 'Réservez vos billets de bus pour '.$routeLabel
                    .' avec Globe One Transport. Paiement sécurisé par Wave ou Orange Money.',
                'robots' => 'noindex, follow',
            ]);
    }

    /* --------------------------------------------------------------------- */
    /*  Validation */
    /* --------------------------------------------------------------------- */

    private function validateBookingForm(): bool
    {
        $this->resetErrorBag();
        $this->formError = null;

        if ($this->passengersCount === null || $this->passengers === []) {
            $this->formError = 'Veuillez choisir le nombre de passagers.';

            return false;
        }

        $formIsValid = true;
        $validPointDepartIds = collect($this->pointDepartOptions)->pluck('id')->all();

        foreach ($this->passengers as $passengerIndex => $passenger) {
            $fullName = trim((string) ($passenger['full_name'] ?? ''));
            $phoneNumber = trim((string) ($passenger['phone_number'] ?? ''));
            $pointDepartId = $passenger['point_dep_id'] ?? null;

            if ($fullName === '') {
                $this->addError("passengers.{$passengerIndex}.full_name", 'Le nom complet est requis.');
                $formIsValid = false;
            } elseif (($nameError = $this->fullNameValidationError($fullName)) !== null) {
                $this->addError("passengers.{$passengerIndex}.full_name", $nameError);
                $formIsValid = false;
            }

            if ($phoneNumber === '') {
                $this->addError("passengers.{$passengerIndex}.phone_number", 'Le numéro de téléphone est requis.');
                $formIsValid = false;
            } elseif (! preg_match(self::SENEGAL_MOBILE_REGEX, $phoneNumber)) {
                $this->addError("passengers.{$passengerIndex}.phone_number", 'Veuillez entrer un numéro de téléphone valide.');
                $formIsValid = false;
            }

            if (blank($pointDepartId)) {
                $this->addError("passengers.{$passengerIndex}.point_dep_id", 'Le point de départ est requis.');
                $formIsValid = false;
            } elseif (! in_array((int) $pointDepartId, $validPointDepartIds, true)) {
                $this->addError("passengers.{$passengerIndex}.point_dep_id", 'Point de départ invalide.');
                $formIsValid = false;
            }
        }

        foreach ($this->duplicatePhoneNumberIndexes() as $duplicateIndex) {
            $this->addError("passengers.{$duplicateIndex}.phone_number", 'Vous avez déjà saisi ce numéro de téléphone pour une autre personne.');
            $formIsValid = false;
        }

        if (! in_array($this->paymentMethod, ['wave', 'om'], true)) {
            $this->addError('paymentMethod', 'Veuillez choisir un moyen de paiement.');
            $formIsValid = false;
        }

        if ($this->paymentMethod === 'om' && ! preg_match(self::SENEGAL_MOBILE_REGEX, trim((string) $this->orangeMoneyNumber))) {
            $this->addError('orangeMoneyNumber', 'Veuillez entrer le numéro Orange Money qui va payer.');
            $formIsValid = false;
        }

        return $formIsValid;
    }

    private function validatePassengerPhoneNumberLive(int $passengerIndex): void
    {
        $phoneNumber = trim((string) ($this->passengers[$passengerIndex]['phone_number'] ?? ''));

        if ($phoneNumber === '') {
            return;
        }

        if (strlen($phoneNumber) !== 9) {
            $this->addError("passengers.{$passengerIndex}.phone_number", 'Le numéro de téléphone doit comporter 9 chiffres.');

            return;
        }

        if (! preg_match(self::SENEGAL_MOBILE_REGEX, $phoneNumber)) {
            $this->addError("passengers.{$passengerIndex}.phone_number", 'Veuillez entrer un numéro de téléphone valide.');

            return;
        }

        if (in_array($passengerIndex, $this->duplicatePhoneNumberIndexes(), true)) {
            $this->addError("passengers.{$passengerIndex}.phone_number", 'Vous avez déjà saisi ce numéro de téléphone pour une autre personne.');

            return;
        }

        // Prefill the full name for a customer we already know (mirrors the mobile client_exists lookup).
        if (blank($this->passengers[$passengerIndex]['full_name'] ?? null)) {
            $knownCustomer = app(MobileAppController::class)->clientExists($phoneNumber)->getData(true);

            if (($knownCustomer['client_exists'] ?? false) && filled($knownCustomer['fullName'] ?? null)) {
                $this->passengers[$passengerIndex]['full_name'] = $knownCustomer['fullName'];
                $this->resetErrorBag("passengers.{$passengerIndex}.full_name");
            }
        }
    }

    private function validatePassengerFullNameLive(int $passengerIndex): void
    {
        $fullName = trim((string) ($this->passengers[$passengerIndex]['full_name'] ?? ''));

        if ($fullName === '') {
            return;
        }

        if (($nameError = $this->fullNameValidationError($fullName)) !== null) {
            $this->addError("passengers.{$passengerIndex}.full_name", $nameError);
        }
    }

    /**
     * Port of the mobile app's validateFullName() (utils/phone_number_validator.js): the full
     * name must be "<prénom(s)> <nom>", separated by a space, min 5 chars, the nom letters-only.
     */
    private function fullNameValidationError(string $fullName): ?string
    {
        $fullName = trim($fullName);

        if (! (str_contains($fullName, ' ') && strpos($fullName, ' ') > 0 && strlen($fullName) > 5)) {
            return 'Le prénom et le nom doivent être séparés par un espace et comporter au moins 5 caractères.';
        }

        $nameParts = preg_split('/\s+/', $fullName);
        $lastName = (string) array_pop($nameParts);
        $firstName = implode(' ', $nameParts);

        if (strlen($lastName) < 2) {
            return 'La longueur du nom doit être au minimum de 2 caractères.';
        }

        // Same character set the mobile app enforces on the "nom" AND what the backend
        // accepts (Customer last_name is validated `alpha_num`): letters only, no apostrophe,
        // space or hyphen — so "N'Diaye" / "Ba Sow" must be typed "Ndiaye" / "Basow".
        if (! preg_match('/^[a-zA-Z.éèÈÉ]+$/u', $lastName)) {
            return 'Le nom doit contenir uniquement des lettres de A à Z (sans apostrophe ni espace).';
        }

        if (strlen($firstName) < 2) {
            return 'La longueur du prénom doit être au minimum de 2 caractères.';
        }

        return null;
    }

    /**
     * @return array<int, int> passenger indexes whose phone number is repeated elsewhere in the form
     */
    private function duplicatePhoneNumberIndexes(): array
    {
        $normalisedPhoneNumbers = collect($this->passengers)
            ->map(fn (array $passenger): string => trim((string) ($passenger['phone_number'] ?? '')))
            ->filter(fn (string $phoneNumber): bool => $phoneNumber !== '');

        return $normalisedPhoneNumbers
            ->duplicates()
            ->keys()
            ->map(fn ($index): int => (int) $index)
            ->all();
    }

    /* --------------------------------------------------------------------- */
    /*  Backend hand-off */
    /* --------------------------------------------------------------------- */

    /**
     * Builds — and runs the full validation of — the exact MobileMultipleBookingRequest the
     * mobile route would resolve, with the départ bound as its route parameter.
     */
    private function buildValidatedMobileBookingRequest(): MobileMultipleBookingRequest
    {
        $mobileRequest = MobileMultipleBookingRequest::create('/', 'POST', $this->bookingPayload());
        $mobileRequest->setContainer(app());
        $mobileRequest->setRedirector(app('redirect'));

        $boundRoute = (new RoutingRoute('POST', '/', []))->bind($mobileRequest);
        $boundRoute->setParameter('depart', $this->depart);
        $mobileRequest->setRouteResolver(fn () => $boundRoute);

        $mobileRequest->validateResolved();

        return $mobileRequest;
    }

    /**
     * @return array<string, mixed>
     */
    private function bookingPayload(): array
    {
        $payload = [
            'bookings' => collect($this->passengers)->map(function (array $passenger): array {
                [$firstName, $lastName] = $this->splitFullName((string) $passenger['full_name']);

                return [
                    'phone_number' => trim((string) $passenger['phone_number']),
                    'point_dep_id' => (int) $passenger['point_dep_id'],
                    'destination_id' => $this->guessedDestinationId(),
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                ];
            })->all(),
            'payment_method' => $this->paymentMethod,
            'booked_with_platform' => 'website',
        ];

        // The backend request auto-picks a bus when none is given, but its normalizeBookings()
        // then reads bus_id straight from validated() — so we always resolve one here, using the
        // same "bus for booking" the départ list / caravane page shows.
        $resolvedBusId = $this->busId ?? $this->depart->getBusForBooking()?->id;
        if ($resolvedBusId !== null) {
            $payload['bus_id'] = $resolvedBusId;
        }

        if ($this->paymentMethod === 'om') {
            $payload['om_number'] = trim((string) $this->orangeMoneyNumber);
        }

        return $payload;
    }

    /**
     * TODO: the destination is currently hard-coded to the trajet's first destination.
     * A later iteration must resolve it from each passenger's chosen drop-off point.
     */
    private function guessedDestinationId(): int
    {
        return (int) collect($this->trajetResource['destinations'] ?? [])->value('id');
    }

    /**
     * @return array{0: string, 1: string} [first name(s), last name]
     */
    private function splitFullName(string $fullName): array
    {
        $nameParts = preg_split('/\s+/', trim($fullName));
        $lastName = (string) array_pop($nameParts);

        return [implode(' ', $nameParts), $lastName];
    }

    /**
     * Stamps every booking of the freshly created group with a shared UUID, used as the public,
     * non-enumerable key of the booking page.
     */
    private function tagGroupBookingsWithUuid(int $groupId): string
    {
        $bookingUuid = (string) Str::uuid();

        Booking::where('group_id', $groupId)
            ->whereNull('uuid')
            ->update(['uuid' => $bookingUuid]);

        return $bookingUuid;
    }

    /* --------------------------------------------------------------------- */
    /*  Small helpers */
    /* --------------------------------------------------------------------- */

    /**
     * Turns a failed payment initiation into a message the customer can act on: the reason the
     * gateway (Wave / Orange Money) itself returned, then the backend's own message, and only
     * as a last resort a generic "try again". Mirrors how the mobile app surfaces
     * PaymentResponseHandler.getOmPaymentError() rather than a canned string.
     *
     * @param  array<string, mixed>  $responseData
     */
    private function paymentInitiationErrorMessage(mixed $paymentResponse, array $responseData): string
    {
        if ($paymentResponse instanceof PaymentResponseResource) {
            $gatewayErrorMessage = $paymentResponse->paymentGatewayErrorMessage();

            if (filled($gatewayErrorMessage)) {
                return $gatewayErrorMessage;
            }
        }

        return data_get($responseData, 'message')
            ?: "Le paiement n'a pas pu être initié. Veuillez réessayer dans un instant.";
    }

    private function totalTicketPrice(): float
    {
        return $this->ticketPrice() * max(1, count($this->passengers));
    }

    private function paymentMethodLabel(): string
    {
        return $this->paymentMethod === 'om' ? 'Orange Money' : 'Wave';
    }

    private function resetBookingStepState(): void
    {
        $this->showSummary = false;
        $this->showNonRefundableWarning = false;
    }
}
