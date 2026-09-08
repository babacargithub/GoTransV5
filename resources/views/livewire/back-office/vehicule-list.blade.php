<div class="mx-auto w-full max-w-4xl">
    <div class="flex items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Véhicules</flux:heading>
            <flux:text class="mt-1">Le parc de véhicules</flux:text>
        </div>

        <flux:button variant="primary" icon="plus" wire:click="openCreateVehicule">
            Créer un véhicule
        </flux:button>
    </div>

    @if (session('status'))
        <flux:callout class="mt-4" variant="success" icon="check-circle">
            <flux:callout.text>{{ session('status') }}</flux:callout.text>
        </flux:callout>
    @endif

    @if (session('error'))
        <flux:callout class="mt-4" variant="danger" icon="exclamation-triangle">
            <flux:callout.text>{{ session('error') }}</flux:callout.text>
        </flux:callout>
    @endif

    <flux:separator class="my-6" variant="subtle" />

    @if (count($this->vehiculeRows) === 0)
        <flux:callout icon="information-circle">
            <flux:callout.heading>Aucun véhicule</flux:callout.heading>
            <flux:callout.text>Créez un véhicule pour commencer.</flux:callout.text>
        </flux:callout>
    @else
        <div class="overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Nom</flux:table.column>
                    <flux:table.column>Matricule</flux:table.column>
                    <flux:table.column>Chauffeur</flux:table.column>
                    <flux:table.column align="center">Places</flux:table.column>
                    <flux:table.column align="center">Type</flux:table.column>
                    <flux:table.column />
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->vehiculeRows as $vehiculeRow)
                        <flux:table.row wire:key="vehicule-{{ $vehiculeRow['id'] }}">
                            <flux:table.cell variant="strong">
                                {{ $vehiculeRow['name'] }}
                                @if ($vehiculeRow['isDefault'])
                                    <flux:badge size="sm" color="blue">Par défaut</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>{{ $vehiculeRow['registrationPlate'] ?? '—' }}</flux:table.cell>
                            <flux:table.cell>{{ $vehiculeRow['driverName'] ?? '—' }}</flux:table.cell>
                            <flux:table.cell align="center">{{ $vehiculeRow['numberOfSeats'] ?? '—' }}</flux:table.cell>
                            <flux:table.cell align="center">
                                <flux:badge size="sm" :color="$vehiculeRow['typeLabel'] === 'Climatisé' ? 'green' : 'zinc'">
                                    {{ $vehiculeRow['typeLabel'] }}
                                </flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="flex items-center justify-end gap-2">
                                    <x-back-office.edit-button
                                        wire:click="openEditVehicule({{ $vehiculeRow['id'] }})"
                                        label="Modifier le véhicule"
                                    />
                                    <x-back-office.delete-button
                                        wire:click="askToDeleteVehicule({{ $vehiculeRow['id'] }})"
                                        label="Supprimer le véhicule"
                                    />
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif

    {{-- Créer / modifier un véhicule --}}
    <flux:modal wire:model.self="showVehiculeModal" wire:key="vehicule-modal" class="w-full max-w-lg">
        <form wire:submit="saveVehicule" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingVehiculeId ? 'Modifier le véhicule' : 'Créer un véhicule' }}</flux:heading>
            </div>

            @if ($vehiculeErrorMessage)
                <flux:callout variant="danger" icon="exclamation-triangle">
                    <flux:callout.text>{{ $vehiculeErrorMessage }}</flux:callout.text>
                </flux:callout>
            @endif

            <flux:field>
                <flux:label>Nom</flux:label>
                <flux:input wire:model="vehiculeName" />
                <flux:error name="vehiculeName" />
            </flux:field>

            <div class="grid gap-6 sm:grid-cols-2">
                <flux:field>
                    <flux:label>Matricule</flux:label>
                    <flux:input wire:model="vehiculeRegistrationPlate" />
                    <flux:error name="vehiculeRegistrationPlate" />
                </flux:field>

                <flux:field>
                    <flux:label>Chauffeur</flux:label>
                    <flux:input wire:model="vehiculeDriverName" />
                    <flux:error name="vehiculeDriverName" />
                </flux:field>
            </div>

            <div class="grid gap-6 sm:grid-cols-2">
                <flux:field>
                    <flux:label>Nombre de places</flux:label>
                    <flux:input type="number" wire:model="vehiculeNumberOfSeats" />
                    <flux:error name="vehiculeNumberOfSeats" />
                </flux:field>

                <flux:field>
                    <flux:label>Type</flux:label>
                    <flux:select wire:model="vehiculeType">
                        @foreach ($this->vehiculeTypeOptions as $typeValue => $typeLabel)
                            <flux:select.option :value="$typeValue">{{ $typeLabel }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="vehiculeType" />
                </flux:field>
            </div>

            <flux:field>
                <flux:label>Description</flux:label>
                <flux:textarea wire:model="vehiculeDescription" rows="2" />
                <flux:error name="vehiculeDescription" />
            </flux:field>

            <flux:switch wire:model="vehiculeIsDefault" label="Véhicule par défaut" />

            <div class="flex items-center justify-end gap-2">
                <flux:button variant="ghost" wire:click="closeVehiculeModal">Annuler</flux:button>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveVehicule">
                    Enregistrer
                </flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Supprimer un véhicule --}}
    <flux:modal wire:model.self="showDeleteVehiculeModal" wire:key="delete-vehicule-modal" class="min-w-[22rem] max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Supprimer ce véhicule ?</flux:heading>
                <flux:text class="mt-2">
                    Voulez-vous vraiment supprimer « {{ $this->deleteVehiculeLabel() }} » ? Cette action est irréversible.
                </flux:text>
            </div>

            <div class="flex items-center justify-end gap-2">
                <flux:button variant="ghost" wire:click="closeDeleteVehiculeModal">Retour</flux:button>
                <flux:button
                    variant="danger"
                    wire:click="confirmDeleteVehicule"
                    wire:loading.attr="disabled"
                    wire:target="confirmDeleteVehicule"
                >
                    Supprimer
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
