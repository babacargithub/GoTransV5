<div class="mx-auto w-full max-w-5xl">
    <div class="flex items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Trajets</flux:heading>
            <flux:text class="mt-1">Les trajets, leurs points de départ et leurs destinations</flux:text>
        </div>
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

    @if (count($this->trajetRows) === 0)
        <flux:callout icon="information-circle">
            <flux:callout.heading>Aucun trajet</flux:callout.heading>
            <flux:callout.text>Les trajets apparaîtront ici une fois créés.</flux:callout.text>
        </flux:callout>
    @else
        <div class="space-y-3">
            @foreach ($this->trajetRows as $trajetRow)
                <flux:card class="flex flex-wrap items-center justify-between gap-4" wire:key="trajet-{{ $trajetRow['id'] }}">
                    <div class="min-w-0">
                        <flux:heading size="lg">{{ $trajetRow['name'] }}</flux:heading>
                        <flux:text class="mt-1 text-sm">
                            @if ($trajetRow['departureCity'] || $trajetRow['arrivalCity'])
                                {{ $trajetRow['departureCity'] ?? '?' }} → {{ $trajetRow['arrivalCity'] ?? '?' }}
                            @else
                                {{ $trajetRow['publicName'] ?? '—' }}
                            @endif
                        </flux:text>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <flux:tooltip content="Gérer les points de départ">
                            <flux:badge size="lg" color="blue" icon="map-pin" wire:click="openPointDepsDialog({{ $trajetRow['id'] }})">
                                {{ $trajetRow['pointDepsCount'] }} point(s) de départ
                            </flux:badge>
                        </flux:tooltip>

                        <flux:tooltip content="Gérer les destinations">
                            <flux:badge size="lg" color="purple" icon="flag" wire:click="openDestinationsDialog({{ $trajetRow['id'] }})">
                                {{ $trajetRow['destinationsCount'] }} destination(s)
                            </flux:badge>
                        </flux:tooltip>

                        <x-back-office.edit-button
                            wire:click="openEditTrajet({{ $trajetRow['id'] }})"
                            label="Modifier le trajet"
                        />

                        <x-back-office.delete-button
                            wire:click="askToDeleteTrajet({{ $trajetRow['id'] }})"
                            label="Supprimer le trajet"
                        />
                    </div>
                </flux:card>
            @endforeach
        </div>
    @endif

    {{-- Points de départ du trajet --}}
    <flux:modal wire:model.self="showPointDepsDialog" wire:key="trajet-point-deps-dialog" class="w-full max-w-2xl">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Points de départ</flux:heading>
                <flux:text class="mt-1">{{ $this->dialogTrajetLabel() }}</flux:text>
            </div>

            @if ($pointDepFormErrorMessage)
                <flux:callout variant="danger" icon="exclamation-triangle">
                    <flux:callout.text>{{ $pointDepFormErrorMessage }}</flux:callout.text>
                </flux:callout>
            @endif

            <div class="flex justify-end">
                <flux:button size="sm" variant="primary" icon="plus" wire:click="startAddingPointDep">
                    Ajouter un point de départ
                </flux:button>
            </div>

            @if ($showPointDepForm)
                <form wire:submit="savePointDepForm" class="space-y-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <flux:heading size="sm">
                        {{ $pointDepForm['id'] === null ? 'Nouveau point de départ' : 'Modifier le point de départ' }}
                    </flux:heading>

                    <flux:field>
                        <flux:label>Nom</flux:label>
                        <flux:input wire:model="pointDepForm.name" />
                        <flux:error name="pointDepForm.name" />
                    </flux:field>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:field>
                            <flux:label>Heure du matin</flux:label>
                            <flux:input type="time" wire:model="pointDepForm.morningSchedule" />
                            <flux:error name="pointDepForm.morningSchedule" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Heure du soir</flux:label>
                            <flux:input type="time" wire:model="pointDepForm.eveningSchedule" />
                            <flux:error name="pointDepForm.eveningSchedule" />
                        </flux:field>
                    </div>

                    <flux:field>
                        <flux:label>Arrêt bus</flux:label>
                        <flux:input wire:model="pointDepForm.busStop" />
                        <flux:error name="pointDepForm.busStop" />
                    </flux:field>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:field>
                            <flux:label>Ville</flux:label>
                            <flux:input wire:model="pointDepForm.city" />
                            <flux:error name="pointDepForm.city" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Prix du ticket</flux:label>
                            <flux:input type="number" wire:model="pointDepForm.ticketPrice" />
                            <flux:error name="pointDepForm.ticketPrice" />
                        </flux:field>
                    </div>

                    <div class="flex justify-end gap-2">
                        <flux:button size="sm" variant="ghost" wire:click="cancelPointDepForm">Annuler</flux:button>
                        <flux:button size="sm" type="submit" variant="primary" wire:loading.attr="disabled" wire:target="savePointDepForm">
                            Enregistrer
                        </flux:button>
                    </div>
                </form>
            @endif

            @if (count($this->dialogPointDepRows) === 0)
                <flux:callout icon="information-circle">
                    <flux:callout.text>Aucun point de départ pour ce trajet.</flux:callout.text>
                </flux:callout>
            @else
                <div class="overflow-x-auto">
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>Nom</flux:table.column>
                            <flux:table.column>Arrêt bus</flux:table.column>
                            <flux:table.column align="center">Matin</flux:table.column>
                            <flux:table.column align="center">Soir</flux:table.column>
                            <flux:table.column />
                        </flux:table.columns>

                        <flux:table.rows>
                            @foreach ($this->dialogPointDepRows as $dialogPointDepRow)
                                <flux:table.row wire:key="dialog-point-dep-{{ $dialogPointDepRow['id'] }}">
                                    <flux:table.cell variant="strong">
                                        {{ $dialogPointDepRow['name'] }}
                                        @if ($dialogPointDepRow['disabled'])
                                            <flux:badge size="sm" color="zinc">Désactivé</flux:badge>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell>{{ $dialogPointDepRow['busStop'] ?? '—' }}</flux:table.cell>
                                    <flux:table.cell align="center">{{ $dialogPointDepRow['morningSchedule'] ?: '—' }}</flux:table.cell>
                                    <flux:table.cell align="center">{{ $dialogPointDepRow['eveningSchedule'] ?: '—' }}</flux:table.cell>
                                    <flux:table.cell>
                                        <div class="flex items-center justify-end gap-2">
                                            <x-back-office.edit-button
                                                wire:click="startEditingPointDep({{ $dialogPointDepRow['id'] }})"
                                                label="Modifier le point de départ"
                                            />
                                            <x-back-office.delete-button
                                                wire:click="deletePointDep({{ $dialogPointDepRow['id'] }})"
                                                label="Supprimer le point de départ"
                                            />
                                        </div>
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </div>
            @endif

            <div class="flex justify-end">
                <flux:button variant="ghost" wire:click="closePointDepsDialog">Fermer</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Destinations du trajet --}}
    <flux:modal wire:model.self="showDestinationsDialog" wire:key="trajet-destinations-dialog" class="w-full max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Destinations</flux:heading>
                <flux:text class="mt-1">{{ $this->dialogTrajetLabel() }}</flux:text>
            </div>

            @if ($destinationFormErrorMessage)
                <flux:callout variant="danger" icon="exclamation-triangle">
                    <flux:callout.text>{{ $destinationFormErrorMessage }}</flux:callout.text>
                </flux:callout>
            @endif

            <div class="flex justify-end">
                <flux:button size="sm" variant="primary" icon="plus" wire:click="startAddingDestination">
                    Ajouter une destination
                </flux:button>
            </div>

            @if ($showDestinationForm)
                <form wire:submit="saveDestinationForm" class="space-y-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <flux:heading size="sm">
                        {{ $destinationForm['id'] === null ? 'Nouvelle destination' : 'Modifier la destination' }}
                    </flux:heading>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:field>
                            <flux:label>Nom</flux:label>
                            <flux:input wire:model="destinationForm.name" />
                            <flux:error name="destinationForm.name" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Tarif</flux:label>
                            <flux:input type="number" wire:model="destinationForm.tarif" />
                            <flux:error name="destinationForm.tarif" />
                        </flux:field>
                    </div>

                    <div class="flex justify-end gap-2">
                        <flux:button size="sm" variant="ghost" wire:click="cancelDestinationForm">Annuler</flux:button>
                        <flux:button size="sm" type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveDestinationForm">
                            Enregistrer
                        </flux:button>
                    </div>
                </form>
            @endif

            @if (count($this->dialogDestinationRows) === 0)
                <flux:callout icon="information-circle">
                    <flux:callout.text>Aucune destination pour ce trajet.</flux:callout.text>
                </flux:callout>
            @else
                <div class="overflow-x-auto">
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>Nom</flux:table.column>
                            <flux:table.column align="end">Tarif</flux:table.column>
                            <flux:table.column />
                        </flux:table.columns>

                        <flux:table.rows>
                            @foreach ($this->dialogDestinationRows as $dialogDestinationRow)
                                <flux:table.row wire:key="dialog-destination-{{ $dialogDestinationRow['id'] }}">
                                    <flux:table.cell variant="strong">{{ $dialogDestinationRow['name'] }}</flux:table.cell>
                                    <flux:table.cell align="end">
                                        {{ $dialogDestinationRow['tarif'] !== null ? number_format($dialogDestinationRow['tarif'], 0, ',', ' ').' FCFA' : '—' }}
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        <div class="flex items-center justify-end gap-2">
                                            <x-back-office.edit-button
                                                wire:click="startEditingDestination({{ $dialogDestinationRow['id'] }})"
                                                label="Modifier la destination"
                                            />
                                            <x-back-office.delete-button
                                                wire:click="deleteDestination({{ $dialogDestinationRow['id'] }})"
                                                label="Supprimer la destination"
                                            />
                                        </div>
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </div>
            @endif

            <div class="flex justify-end">
                <flux:button variant="ghost" wire:click="closeDestinationsDialog">Fermer</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Modifier un trajet --}}
    <flux:modal wire:model.self="showEditTrajetModal" wire:key="edit-trajet-modal" class="w-full max-w-lg">
        <form wire:submit="saveEditedTrajet" class="space-y-6">
            <div>
                <flux:heading size="lg">Modifier le trajet</flux:heading>
            </div>

            @if ($editTrajetErrorMessage)
                <flux:callout variant="danger" icon="exclamation-triangle">
                    <flux:callout.text>{{ $editTrajetErrorMessage }}</flux:callout.text>
                </flux:callout>
            @endif

            <flux:field>
                <flux:label>Nom</flux:label>
                <flux:input wire:model="editTrajetName" />
                <flux:error name="editTrajetName" />
            </flux:field>

            <flux:field>
                <flux:label>Nom public</flux:label>
                <flux:input wire:model="editTrajetPublicName" />
                <flux:error name="editTrajetPublicName" />
            </flux:field>

            <div class="grid gap-6 sm:grid-cols-2">
                <flux:field>
                    <flux:label>Ville de départ</flux:label>
                    <flux:input wire:model="editTrajetDepartureCity" />
                    <flux:error name="editTrajetDepartureCity" />
                </flux:field>

                <flux:field>
                    <flux:label>Ville d'arrivée</flux:label>
                    <flux:input wire:model="editTrajetArrivalCity" />
                    <flux:error name="editTrajetArrivalCity" />
                </flux:field>
            </div>

            <div class="grid gap-6 sm:grid-cols-2">
                <flux:field>
                    <flux:label>Code</flux:label>
                    <flux:input wire:model="editTrajetCode" />
                    <flux:error name="editTrajetCode" />
                </flux:field>

                <flux:field>
                    <flux:label>Distance (km)</flux:label>
                    <flux:input type="number" wire:model="editTrajetLength" />
                    <flux:error name="editTrajetLength" />
                </flux:field>
            </div>

            <div class="flex items-center justify-end gap-2">
                <flux:button variant="ghost" wire:click="closeEditTrajet">Annuler</flux:button>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveEditedTrajet">
                    Enregistrer
                </flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Supprimer un trajet --}}
    <flux:modal wire:model.self="showDeleteTrajetModal" wire:key="delete-trajet-modal" class="min-w-[22rem] max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Supprimer ce trajet ?</flux:heading>
                <flux:text class="mt-2">
                    Voulez-vous vraiment supprimer « {{ $this->deleteTrajetLabel() }} » ? Cette action est irréversible.
                </flux:text>
            </div>

            <div class="flex items-center justify-end gap-2">
                <flux:button variant="ghost" wire:click="closeDeleteTrajetModal">Retour</flux:button>
                <flux:button
                    variant="danger"
                    wire:click="confirmDeleteTrajet"
                    wire:loading.attr="disabled"
                    wire:target="confirmDeleteTrajet"
                >
                    Supprimer
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
