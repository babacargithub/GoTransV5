<div class="mx-auto w-full max-w-5xl">
    <div class="flex items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Points de départ</flux:heading>
            <flux:text class="mt-1">Tous les points de départ, groupés par trajet</flux:text>
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

    @if (count($this->pointDepRows) === 0)
        <flux:callout icon="information-circle">
            <flux:callout.heading>Aucun point de départ</flux:callout.heading>
            <flux:callout.text>Les points de départ des trajets apparaîtront ici.</flux:callout.text>
        </flux:callout>
    @else
        <div class="overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Nom</flux:table.column>
                    <flux:table.column>Trajet</flux:table.column>
                    <flux:table.column>Arrêt bus</flux:table.column>
                    <flux:table.column align="center">Matin</flux:table.column>
                    <flux:table.column align="center">Soir</flux:table.column>
                    <flux:table.column align="center">Statut</flux:table.column>
                    <flux:table.column />
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->pointDepRows as $pointDepRow)
                        <flux:table.row wire:key="point-dep-{{ $pointDepRow['id'] }}">
                            <flux:table.cell variant="strong">{{ $pointDepRow['name'] }}</flux:table.cell>
                            <flux:table.cell>{{ $pointDepRow['trajetName'] ?? '—' }}</flux:table.cell>
                            <flux:table.cell>{{ $pointDepRow['busStop'] ?? '—' }}</flux:table.cell>
                            <flux:table.cell align="center">{{ $pointDepRow['morningSchedule'] ?: '—' }}</flux:table.cell>
                            <flux:table.cell align="center">{{ $pointDepRow['eveningSchedule'] ?: '—' }}</flux:table.cell>
                            <flux:table.cell align="center">
                                @if ($pointDepRow['disabled'])
                                    <flux:badge size="sm" color="zinc">Désactivé</flux:badge>
                                @else
                                    <flux:badge size="sm" color="green">Actif</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="flex items-center justify-end gap-2">
                                    <x-back-office.edit-button
                                        wire:click="openEditPointDep({{ $pointDepRow['id'] }})"
                                        label="Modifier le point de départ"
                                    />

                                    <flux:tooltip :content="$pointDepRow['disabled'] ? 'Réactiver' : 'Désactiver'">
                                        <flux:button
                                            variant="filled"
                                            size="sm"
                                            :icon="$pointDepRow['disabled'] ? 'eye' : 'eye-slash'"
                                            :aria-label="$pointDepRow['disabled'] ? 'Réactiver le point de départ' : 'Désactiver le point de départ'"
                                            wire:click="togglePointDepDisabled({{ $pointDepRow['id'] }})"
                                        />
                                    </flux:tooltip>

                                    <x-back-office.delete-button
                                        wire:click="askToDeletePointDep({{ $pointDepRow['id'] }})"
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

    {{-- Modifier un point de départ --}}
    <flux:modal wire:model.self="showEditPointDepModal" wire:key="edit-point-dep-modal" class="w-full max-w-lg">
        <form wire:submit="saveEditedPointDep" class="space-y-6">
            <div>
                <flux:heading size="lg">Modifier le point de départ</flux:heading>
            </div>

            @if ($editPointDepErrorMessage)
                <flux:callout variant="danger" icon="exclamation-triangle">
                    <flux:callout.text>{{ $editPointDepErrorMessage }}</flux:callout.text>
                </flux:callout>
            @endif

            <flux:field>
                <flux:label>Nom</flux:label>
                <flux:input wire:model="editPointDepName" />
                <flux:error name="editPointDepName" />
            </flux:field>

            <div class="grid gap-6 sm:grid-cols-2">
                <flux:field>
                    <flux:label>Heure du matin</flux:label>
                    <flux:input type="time" wire:model="editPointDepMorningSchedule" />
                    <flux:error name="editPointDepMorningSchedule" />
                </flux:field>

                <flux:field>
                    <flux:label>Heure du soir</flux:label>
                    <flux:input type="time" wire:model="editPointDepEveningSchedule" />
                    <flux:error name="editPointDepEveningSchedule" />
                </flux:field>
            </div>

            <flux:field>
                <flux:label>Arrêt bus</flux:label>
                <flux:input wire:model="editPointDepBusStop" />
                <flux:error name="editPointDepBusStop" />
            </flux:field>

            <div class="grid gap-6 sm:grid-cols-2">
                <flux:field>
                    <flux:label>Ville</flux:label>
                    <flux:input wire:model="editPointDepCity" />
                    <flux:error name="editPointDepCity" />
                </flux:field>

                <flux:field>
                    <flux:label>Prix du ticket</flux:label>
                    <flux:input type="number" wire:model="editPointDepTicketPrice" />
                    <flux:error name="editPointDepTicketPrice" />
                </flux:field>
            </div>

            <div class="flex items-center justify-end gap-2">
                <flux:button variant="ghost" wire:click="closeEditPointDep">Annuler</flux:button>
                <flux:button
                    type="submit"
                    variant="primary"
                    wire:loading.attr="disabled"
                    wire:target="saveEditedPointDep"
                >
                    Enregistrer
                </flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Supprimer un point de départ --}}
    <flux:modal wire:model.self="showDeletePointDepModal" wire:key="delete-point-dep-modal" class="min-w-[22rem] max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Supprimer ce point de départ ?</flux:heading>
                <flux:text class="mt-2">
                    Voulez-vous vraiment supprimer « {{ $this->deletePointDepLabel() }} » ? Cette action est irréversible.
                </flux:text>
            </div>

            <div class="flex items-center justify-end gap-2">
                <flux:button variant="ghost" wire:click="closeDeletePointDepModal">Retour</flux:button>
                <flux:button
                    variant="danger"
                    wire:click="confirmDeletePointDep"
                    wire:loading.attr="disabled"
                    wire:target="confirmDeletePointDep"
                >
                    Supprimer
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
