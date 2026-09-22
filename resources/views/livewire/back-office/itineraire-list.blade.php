<div class="mx-auto w-full max-w-4xl">
    <div class="flex items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Itinéraires</flux:heading>
            <flux:text class="mt-1">Les itinéraires et les points de départ qu'ils couvrent</flux:text>
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

    @if (count($this->itineraireRows) === 0)
        <flux:callout icon="information-circle">
            <flux:callout.heading>Aucun itinéraire</flux:callout.heading>
            <flux:callout.text>Les itinéraires apparaîtront ici une fois créés.</flux:callout.text>
        </flux:callout>
    @else
        <div class="overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Nom</flux:table.column>
                    <flux:table.column>Trajet</flux:table.column>
                    <flux:table.column align="center">Points de départ</flux:table.column>
                    <flux:table.column align="center">Statut</flux:table.column>
                    <flux:table.column />
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->itineraireRows as $itineraireRow)
                        <flux:table.row wire:key="itineraire-{{ $itineraireRow['id'] }}">
                            <flux:table.cell variant="strong">{{ $itineraireRow['name'] }}</flux:table.cell>
                            <flux:table.cell>{{ $itineraireRow['trajetName'] ?? '—' }}</flux:table.cell>
                            <flux:table.cell align="center">
                                <flux:badge size="sm" color="blue">{{ $itineraireRow['pointDepsCount'] }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell align="center">
                                @if ($itineraireRow['disabled'])
                                    <flux:badge size="sm" color="zinc">Désactivé</flux:badge>
                                @else
                                    <flux:badge size="sm" color="green">Actif</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="flex items-center justify-end gap-2">
                                    <x-back-office.edit-button
                                        wire:click="openEditItineraire({{ $itineraireRow['id'] }})"
                                        label="Modifier l'itinéraire"
                                    />

                                    <flux:tooltip :content="$itineraireRow['disabled'] ? 'Réactiver' : 'Désactiver'">
                                        <flux:button
                                            variant="filled"
                                            size="sm"
                                            :icon="$itineraireRow['disabled'] ? 'eye' : 'eye-slash'"
                                            :aria-label="$itineraireRow['disabled'] ? 'Réactiver l\'itinéraire' : 'Désactiver l\'itinéraire'"
                                            wire:click="toggleItineraireDisabled({{ $itineraireRow['id'] }})"
                                        />
                                    </flux:tooltip>

                                    <x-back-office.delete-button
                                        wire:click="askToDeleteItineraire({{ $itineraireRow['id'] }})"
                                        label="Supprimer l'itinéraire"
                                    />
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif

    {{-- Modifier un itinéraire --}}
    <flux:modal wire:model.self="showEditItineraireModal" wire:key="edit-itineraire-modal" class="w-full max-w-lg">
        <form wire:submit="saveEditedItineraire" class="space-y-6">
            <div>
                <flux:heading size="lg">Modifier l'itinéraire</flux:heading>
            </div>

            @if ($editItineraireErrorMessage)
                <flux:callout variant="danger" icon="exclamation-triangle">
                    <flux:callout.text>{{ $editItineraireErrorMessage }}</flux:callout.text>
                </flux:callout>
            @endif

            <flux:field>
                <flux:label>Nom</flux:label>
                <flux:input wire:model="editItineraireName" />
                <flux:error name="editItineraireName" />
            </flux:field>

            <flux:field>
                <flux:label>Trajet</flux:label>
                <flux:select wire:model.live="editItineraireTrajetId" placeholder="Choisir un trajet">
                    @foreach ($this->trajetOptions as $trajetOptionId => $trajetOptionName)
                        <flux:select.option :value="$trajetOptionId">{{ $trajetOptionName }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="editItineraireTrajetId" />
            </flux:field>

            <flux:field>
                <flux:label>Points de départ</flux:label>
                @if (count($this->editItinerairePointDepOptions) === 0)
                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                        Choisissez d'abord un trajet.
                    </flux:text>
                @else
                    <flux:checkbox.group wire:model="editItinerairePointDepIds" class="max-h-60 space-y-2 overflow-y-auto">
                        @foreach ($this->editItinerairePointDepOptions as $pointDepOption)
                            <flux:checkbox
                                :value="$pointDepOption['id']"
                                :label="$pointDepOption['name']"
                                wire:key="itineraire-point-dep-{{ $pointDepOption['id'] }}"
                            />
                        @endforeach
                    </flux:checkbox.group>
                @endif
                <flux:error name="editItinerairePointDepIds" />
            </flux:field>

            <div class="flex items-center justify-end gap-2">
                <flux:button variant="ghost" wire:click="closeEditItineraire">Annuler</flux:button>
                <flux:button
                    type="submit"
                    variant="primary"
                    wire:loading.attr="disabled"
                    wire:target="saveEditedItineraire"
                >
                    Enregistrer
                </flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Supprimer un itinéraire --}}
    <flux:modal wire:model.self="showDeleteItineraireModal" wire:key="delete-itineraire-modal" class="min-w-[22rem] max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Supprimer cet itinéraire ?</flux:heading>
                <flux:text class="mt-2">
                    Voulez-vous vraiment supprimer « {{ $this->deleteItineraireLabel() }} » ? Cette action est irréversible.
                </flux:text>
            </div>

            <div class="flex items-center justify-end gap-2">
                <flux:button variant="ghost" wire:click="closeDeleteItineraireModal">Retour</flux:button>
                <flux:button
                    variant="danger"
                    wire:click="confirmDeleteItineraire"
                    wire:loading.attr="disabled"
                    wire:target="confirmDeleteItineraire"
                >
                    Supprimer
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
