<div class="mx-auto w-full max-w-2xl">
    <flux:button
        :href="route('back-office.departs.index')"
        variant="ghost"
        size="sm"
        icon="arrow-left"
        class="mb-4"
    >
        Retour aux départs
    </flux:button>

    <flux:heading size="xl" level="1">Nouveau départ</flux:heading>
    <flux:text class="mt-1">Cochez une ou plusieurs dates : un départ est créé pour chacune.</flux:text>

    @if ($errorMessage)
        <flux:callout class="mt-4" variant="danger" icon="exclamation-triangle" wire:key="create-depart-error">
            <flux:callout.text>{{ $errorMessage }}</flux:callout.text>
        </flux:callout>
    @endif

    <flux:separator class="my-6" variant="subtle" />

    <form wire:submit="save" class="space-y-6">
        <flux:field>
            <flux:label>Trajet</flux:label>
            <flux:select wire:model="trajetId" placeholder="Choisir un trajet">
                @foreach ($this->trajetOptions as $trajetOptionId => $trajetOptionName)
                    <flux:select.option :value="$trajetOptionId">{{ $trajetOptionName }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:error name="trajetId" />
        </flux:field>

        <flux:field>
            <flux:label>Horaire</flux:label>
            <flux:select wire:model="horaireId" placeholder="Choisir un horaire">
                @foreach ($this->horaireOptions as $horaireOptionId => $horaireOptionName)
                    <flux:select.option :value="$horaireOptionId">{{ $horaireOptionName }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:error name="horaireId" />
        </flux:field>

        <flux:field>
            <flux:label>Visibilité</flux:label>
            <flux:select wire:model="visibility">
                @foreach ($this->visibilityOptions as $visibilityValue => $visibilityLabel)
                    <flux:select.option :value="$visibilityValue">{{ $visibilityLabel }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:error name="visibility" />
        </flux:field>

        <flux:radio.group wire:model="busTypeToCreate" label="Type de bus à créer">
            @foreach ($this->busTypeOptions as $busTypeValue => $busTypeLabel)
                <flux:radio :value="$busTypeValue" :label="$busTypeLabel" />
            @endforeach
            <flux:error name="busTypeToCreate" />
        </flux:radio.group>

        <flux:separator class="my-2" variant="subtle" text="Détails du bus" />

        <flux:field>
            <flux:label>Véhicule transport</flux:label>
            <flux:select wire:model.live="vehiculeId" placeholder="Choisir un véhicule">
                @foreach ($this->vehiculeOptions as $vehiculeOptionId => $vehiculeOption)
                    <flux:select.option :value="$vehiculeOptionId">
                        {{ $vehiculeOption['name'] }}{{ $vehiculeOption['isAirConditioned'] ? ' (climatisé)' : '' }}
                    </flux:select.option>
                @endforeach
            </flux:select>
            <flux:error name="vehiculeId" />
        </flux:field>

        <flux:field>
            <flux:label>Nom du Bus</flux:label>
            <flux:input wire:model="busName" placeholder="Bus" />
            <flux:error name="busName" />
        </flux:field>

        <div class="grid gap-6 sm:grid-cols-2">
            <flux:field>
                <flux:label>Nombre de place</flux:label>
                <flux:input type="number" min="1" wire:model="numberOfSeats" />
                <flux:error name="numberOfSeats" />
            </flux:field>

            <flux:field>
                <flux:label>Prix Ticket</flux:label>
                <flux:input type="number" min="0" step="1" wire:model="ticketPrice" />
                <flux:error name="ticketPrice" />
            </flux:field>
        </div>

        <flux:field>
            <flux:label>Prix Grand Public</flux:label>
            <flux:input type="number" min="0" step="1" wire:model="grandPublicTicketPrice" />
            <flux:error name="grandPublicTicketPrice" />
        </flux:field>

        <flux:field>
            <flux:label>Template</flux:label>
            <flux:input wire:model="departNameTemplate" />
            <flux:description>«&nbsp;&lt;Date&gt;&nbsp;» est remplacé par la date du départ (ex. «&nbsp;lundi 08 septembre&nbsp;»).</flux:description>
            <flux:error name="departNameTemplate" />
        </flux:field>

        <flux:checkbox.group
            wire:model="selectedDepartureDates"
            label="Dates"
            :description="count($selectedDepartureDates) . ' date(s) cochée(s).'"
        >
            <div class="mt-2 max-h-80 space-y-4 overflow-y-auto rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                @foreach ($this->upcomingDepartureDatesByMonth as $monthHeading => $datesInMonth)
                    <div wire:key="month-{{ $loop->index }}">
                        <flux:heading size="sm" class="mb-2 capitalize">{{ $monthHeading }}</flux:heading>

                        <div class="grid gap-2 sm:grid-cols-2">
                            @foreach ($datesInMonth as $isoDate => $dateLabel)
                                <flux:checkbox
                                    wire:key="date-{{ $isoDate }}"
                                    :value="$isoDate"
                                    :label="ucfirst($dateLabel)"
                                />
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>

            <flux:error name="selectedDepartureDates" />
        </flux:checkbox.group>

        <div class="flex items-center justify-end gap-2">
            <flux:button :href="route('back-office.departs.index')" variant="ghost">Annuler</flux:button>

            <flux:button
                type="submit"
                variant="primary"
                wire:loading.attr="disabled"
                wire:target="save"
            >
                Enregistrer
            </flux:button>
        </div>
    </form>
</div>
