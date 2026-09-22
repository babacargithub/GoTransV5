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

    <flux:heading size="xl" level="1">Ajouter un bus</flux:heading>
    <flux:text class="mt-1">{{ $depart->identifier(with_trajet_prefix: true) }}</flux:text>

    @if ($errorMessage)
        <flux:callout class="mt-4" variant="danger" icon="exclamation-triangle" wire:key="add-bus-error">
            <flux:callout.text>{{ $errorMessage }}</flux:callout.text>
        </flux:callout>
    @endif

    <flux:separator class="my-6" variant="subtle" />

    <form wire:submit="save" class="space-y-6">
        <flux:field>
            <flux:label>Véhicule transport</flux:label>
            <flux:select wire:model.live="vehiculeId" placeholder="Choisir un véhicule">
                @foreach ($this->vehiculeOptions as $vehicule)
                    <flux:select.option :value="$vehicule['id']">{{ $vehicule['name'] }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:error name="vehiculeId" />
        </flux:field>

        <flux:field>
            <flux:label>Nom du Bus</flux:label>
            <flux:input wire:model="name" placeholder="Bus 2" />
            <flux:error name="name" />
        </flux:field>

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

        <flux:field>
            <flux:label badge="Optionnel">Prix Ticket GP</flux:label>
            <flux:input type="number" min="0" step="1" wire:model="gpTicketPrice" />
            <flux:error name="gpTicketPrice" />
        </flux:field>

        <flux:field>
            <flux:label badge="Optionnel">Numéro convoyeur</flux:label>
            <flux:input wire:model="convoyorPhoneNumbers" placeholder="77xxxxxxx/78xxxxxxx" />
            <flux:description>Séparez plusieurs numéros par «&nbsp;/&nbsp;».</flux:description>
            <flux:error name="convoyorPhoneNumbers" />
        </flux:field>

        <flux:field>
            <flux:label badge="Optionnel">Itinéraire</flux:label>
            <flux:select wire:model="itineraryId" placeholder="Choisir un itinéraire">
                @foreach ($this->itineraryOptions as $itinerary)
                    <flux:select.option :value="$itinerary['id']">{{ $itinerary['name'] }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:error name="itineraryId" />
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
