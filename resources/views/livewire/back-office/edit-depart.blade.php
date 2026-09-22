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

    <flux:heading size="xl" level="1">Modifier départ</flux:heading>
    <flux:text class="mt-1">Trajet : {{ $this->trajetName }}</flux:text>

    @if ($errorMessage)
        <flux:callout class="mt-4" variant="danger" icon="exclamation-triangle" wire:key="edit-depart-error">
            <flux:callout.text>{{ $errorMessage }}</flux:callout.text>
        </flux:callout>
    @endif

    <flux:separator class="my-6" variant="subtle" />

    <form wire:submit="save" class="space-y-6">
        <flux:field>
            <flux:label>Nom du départ</flux:label>
            <flux:input wire:model="departName" />
            <flux:error name="departName" />
        </flux:field>

        <div class="grid gap-6 sm:grid-cols-2">
            <flux:field>
                <flux:label>Date de départ</flux:label>
                <flux:input type="date" wire:model="departureDate" />
                <flux:error name="departureDate" />
            </flux:field>

            <flux:field>
                <flux:label>Heure de départ</flux:label>
                <flux:input type="time" wire:model="departureTime" />
                <flux:error name="departureTime" />
            </flux:field>
        </div>

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
