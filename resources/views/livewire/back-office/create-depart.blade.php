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
            <flux:select wire:model.live="trajetId" placeholder="Choisir un trajet">
                @foreach ($this->trajetOptions as $trajetOptionId => $trajetOptionName)
                    <flux:select.option :value="$trajetOptionId">{{ $trajetOptionName }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:error name="trajetId" />
        </flux:field>

        <flux:field>
            <flux:label>Horaire</flux:label>
            <flux:select wire:model.live="horaireId" placeholder="Choisir un horaire">
                @foreach ($this->horaireOptions as $horaireOptionId => $horaireOptionName)
                    <flux:select.option :value="$horaireOptionId">{{ $horaireOptionName }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:error name="horaireId" />
        </flux:field>

        <flux:field>
            <flux:label>Heure de fin du départ</flux:label>
            <flux:input type="time" wire:model="departureTime" />
            <flux:description>Heure à laquelle le départ prend fin (pré-remplie depuis l'horaire, modifiable).</flux:description>
            <flux:error name="departureTime" />
        </flux:field>

        <flux:field>
            <flux:label>Itinéraire par défaut</flux:label>
            <flux:select wire:model="itineraryId" placeholder="Aucun itinéraire">
                @foreach ($this->itineraryOptions as $itineraryOptionId => $itineraryOptionName)
                    <flux:select.option :value="$itineraryOptionId">{{ $itineraryOptionName }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:description>Appliqué à tous les bus créés pour ces départs.</flux:description>
            <flux:error name="itineraryId" />
        </flux:field>

        @if (count($busStopSchedules) === 0)
            <flux:callout icon="information-circle" variant="secondary">
                <flux:callout.text>
                    @if ($trajetId === null || $horaireId === null)
                        Choisissez un trajet <strong>et</strong> un horaire pour afficher et ajuster les rendez-vous.
                    @else
                        Aucun arrêt actif n'est configuré pour ce trajet : aucun rendez-vous à ajuster.
                    @endif
                </flux:callout.text>
            </flux:callout>
        @else
            <div class="space-y-3">
                <div>
                    <flux:heading size="sm">Rendez-vous</flux:heading>
                    <flux:text size="sm">Ajustez le lieu et l'heure de rendez-vous de chaque arrêt.</flux:text>
                </div>

                @foreach ($busStopSchedules as $scheduleIndex => $busStopSchedule)
                    <div
                        wire:key="schedule-{{ $busStopSchedule['pointDepId'] }}"
                        class="grid items-end gap-3 rounded-lg border border-zinc-200 p-3 sm:grid-cols-[1fr_1fr_7rem_auto] dark:border-zinc-700"
                    >
                        <flux:heading class="sm:pb-2">{{ $busStopSchedule['pointDepName'] }}</flux:heading>

                        <flux:field>
                            <flux:input wire:model="busStopSchedules.{{ $scheduleIndex }}.rendezVousPoint" aria-label="Point de rendez-vous" />
                            <flux:error name="busStopSchedules.{{ $scheduleIndex }}.rendezVousPoint" />
                        </flux:field>

                        <flux:field>
                            <flux:input type="time" wire:model="busStopSchedules.{{ $scheduleIndex }}.rendezVousSchedule" aria-label="Heure de rendez-vous" />
                            <flux:error name="busStopSchedules.{{ $scheduleIndex }}.rendezVousSchedule" />
                        </flux:field>

                        <flux:switch wire:model="busStopSchedules.{{ $scheduleIndex }}.isActive" label="Actif" class="sm:pb-2" />
                    </div>
                @endforeach
            </div>
        @endif

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
