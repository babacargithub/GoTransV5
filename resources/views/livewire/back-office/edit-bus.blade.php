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

    <flux:heading size="xl" level="1">Modifier infos bus</flux:heading>
    <flux:text class="mt-1">
        Départ : {{ $this->departLabel }}
        @if ($this->vehiculeName)
            — Véhicule : {{ $this->vehiculeName }}
        @endif
    </flux:text>

    @if ($errorMessage)
        <flux:callout class="mt-4" variant="danger" icon="exclamation-triangle" wire:key="edit-bus-error">
            <flux:callout.text>{{ $errorMessage }}</flux:callout.text>
        </flux:callout>
    @endif

    <flux:separator class="my-6" variant="subtle" />

    <form wire:submit="save" class="space-y-6">
        <flux:field>
            <flux:label>Nom du bus</flux:label>
            <flux:input wire:model="busName" />
            <flux:error name="busName" />
        </flux:field>

        <flux:field>
            <flux:label>Nombre de places</flux:label>
            <flux:input type="number" wire:model="numberOfSeats" />
            <flux:error name="numberOfSeats" />
        </flux:field>

        <div class="grid gap-6 sm:grid-cols-2">
            <flux:field>
                <flux:label>Prix du ticket</flux:label>
                <flux:input type="number" wire:model="ticketPrice" />
                <flux:error name="ticketPrice" />
            </flux:field>

            <flux:field>
                <flux:label>Prix du ticket GP</flux:label>
                <flux:input type="number" wire:model="gpTicketPrice" />
                <flux:error name="gpTicketPrice" />
            </flux:field>
        </div>

        <flux:field>
            <flux:label>Numéro du convoyeur</flux:label>
            <flux:input wire:model="agentNumbers" placeholder="77xxxxxxx / 78xxxxxxx" />
            <flux:description>Séparez plusieurs numéros par « / ».</flux:description>
            <flux:error name="agentNumbers" />
        </flux:field>

        <flux:field>
            <flux:label>Itinéraire</flux:label>
            <flux:select wire:model="itineraryId" placeholder="Choisir un itinéraire">
                @foreach ($this->itineraryOptions as $itineraryOptionId => $itineraryOptionName)
                    <flux:select.option :value="$itineraryOptionId">{{ $itineraryOptionName }}</flux:select.option>
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
