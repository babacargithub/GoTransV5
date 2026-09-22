<div class="mx-auto w-full max-w-4xl">
    <div class="flex items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Horaires</flux:heading>
            <flux:text class="mt-1">Les horaires de départ des bus, par trajet</flux:text>
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

    @if (count($this->horaireRows) === 0)
        <flux:callout icon="information-circle">
            <flux:callout.heading>Aucun horaire</flux:callout.heading>
            <flux:callout.text>Les horaires apparaîtront ici une fois créés.</flux:callout.text>
        </flux:callout>
    @else
        <div class="overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Nom</flux:table.column>
                    <flux:table.column>Trajet</flux:table.column>
                    <flux:table.column align="center">Départ bus</flux:table.column>
                    <flux:table.column align="center">Période</flux:table.column>
                    <flux:table.column />
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->horaireRows as $horaireRow)
                        <flux:table.row wire:key="horaire-{{ $horaireRow['id'] }}">
                            <flux:table.cell variant="strong">{{ $horaireRow['name'] }}</flux:table.cell>
                            <flux:table.cell>{{ $horaireRow['trajetName'] ?? '—' }}</flux:table.cell>
                            <flux:table.cell align="center">{{ $horaireRow['busLeaveTime'] ?: '—' }}</flux:table.cell>
                            <flux:table.cell align="center">
                                @if ($horaireRow['periode'])
                                    <flux:badge size="sm" color="zinc">{{ ucfirst($horaireRow['periode']) }}</flux:badge>
                                @else
                                    —
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="flex items-center justify-end gap-2">
                                    <x-back-office.edit-button
                                        wire:click="openEditHoraire({{ $horaireRow['id'] }})"
                                        label="Modifier l'horaire"
                                    />

                                    <x-back-office.delete-button
                                        wire:click="askToDeleteHoraire({{ $horaireRow['id'] }})"
                                        label="Supprimer l'horaire"
                                    />
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif

    {{-- Modifier un horaire --}}
    <flux:modal wire:model.self="showEditHoraireModal" wire:key="edit-horaire-modal" class="w-full max-w-lg">
        <form wire:submit="saveEditedHoraire" class="space-y-6">
            <div>
                <flux:heading size="lg">Modifier l'horaire</flux:heading>
            </div>

            <flux:field>
                <flux:label>Nom</flux:label>
                <flux:input wire:model="editHoraireName" />
                <flux:error name="editHoraireName" />
            </flux:field>

            <flux:field>
                <flux:label>Trajet</flux:label>
                <flux:select wire:model="editHoraireTrajetId" placeholder="Choisir un trajet">
                    @foreach ($this->trajetOptions as $trajetOptionId => $trajetOptionName)
                        <flux:select.option :value="$trajetOptionId">{{ $trajetOptionName }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="editHoraireTrajetId" />
            </flux:field>

            <div class="grid gap-6 sm:grid-cols-2">
                <flux:field>
                    <flux:label>Heure de départ du bus</flux:label>
                    <flux:input type="time" wire:model="editHoraireBusLeaveTime" />
                    <flux:error name="editHoraireBusLeaveTime" />
                </flux:field>

                <flux:field>
                    <flux:label>Période</flux:label>
                    <flux:select wire:model="editHorairePeriode" placeholder="Choisir une période">
                        @foreach ($this->periodeOptions as $periodeValue => $periodeLabel)
                            <flux:select.option :value="$periodeValue">{{ $periodeLabel }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="editHorairePeriode" />
                </flux:field>
            </div>

            <div class="flex items-center justify-end gap-2">
                <flux:button variant="ghost" wire:click="closeEditHoraire">Annuler</flux:button>
                <flux:button
                    type="submit"
                    variant="primary"
                    wire:loading.attr="disabled"
                    wire:target="saveEditedHoraire"
                >
                    Enregistrer
                </flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Supprimer un horaire --}}
    <flux:modal wire:model.self="showDeleteHoraireModal" wire:key="delete-horaire-modal" class="min-w-[22rem] max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Supprimer cet horaire ?</flux:heading>
                <flux:text class="mt-2">
                    Voulez-vous vraiment supprimer « {{ $this->deleteHoraireLabel() }} » ? Cette action est irréversible.
                </flux:text>
            </div>

            <div class="flex items-center justify-end gap-2">
                <flux:button variant="ghost" wire:click="closeDeleteHoraireModal">Retour</flux:button>
                <flux:button
                    variant="danger"
                    wire:click="confirmDeleteHoraire"
                    wire:loading.attr="disabled"
                    wire:target="confirmDeleteHoraire"
                >
                    Supprimer
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
