<div class="mx-auto w-full max-w-5xl">
    <div class="flex items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Employés</flux:heading>
            <flux:text class="mt-1">Le personnel, ses coordonnées et ses permissions</flux:text>
        </div>

        <flux:button variant="primary" icon="plus" wire:click="openCreateEmploye">
            Ajouter un employé
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

    @if (count($this->employeRows) === 0)
        <flux:callout icon="information-circle">
            <flux:callout.heading>Aucun employé</flux:callout.heading>
            <flux:callout.text>Ajoutez un employé pour commencer.</flux:callout.text>
        </flux:callout>
    @else
        <div class="overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Nom complet</flux:table.column>
                    <flux:table.column>Téléphone</flux:table.column>
                    <flux:table.column>Poste</flux:table.column>
                    <flux:table.column>Catégorie</flux:table.column>
                    <flux:table.column>Permissions</flux:table.column>
                    <flux:table.column align="center">Statut</flux:table.column>
                    <flux:table.column />
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->employeRows as $employeRow)
                        <flux:table.row wire:key="employe-{{ $employeRow['id'] }}">
                            <flux:table.cell variant="strong">{{ $employeRow['fullName'] }}</flux:table.cell>
                            <flux:table.cell>{{ $employeRow['phoneNumber'] ?? '—' }}</flux:table.cell>
                            <flux:table.cell>{{ $employeRow['jobTitle'] ?? '—' }}</flux:table.cell>
                            <flux:table.cell>{{ $employeRow['categoryName'] ?? '—' }}</flux:table.cell>
                            <flux:table.cell>
                                <div class="flex flex-wrap gap-1">
                                    @if ($employeRow['canSellTicket'])
                                        <flux:badge size="sm" color="green" icon="ticket">Billets</flux:badge>
                                    @endif
                                    @if ($employeRow['canCancelPaidBooking'])
                                        <flux:badge size="sm" color="amber" icon="x-circle">Annulation</flux:badge>
                                    @endif
                                    @if ($employeRow['canChooseSeats'])
                                        <flux:badge size="sm" color="blue" icon="check-circle">Sièges</flux:badge>
                                    @endif
                                    @unless ($employeRow['canSellTicket'] || $employeRow['canCancelPaidBooking'] || $employeRow['canChooseSeats'])
                                        <flux:text class="text-sm text-zinc-400">—</flux:text>
                                    @endunless
                                </div>
                            </flux:table.cell>
                            <flux:table.cell align="center">
                                @if ($employeRow['isActive'])
                                    <flux:badge size="sm" color="green">Actif</flux:badge>
                                @else
                                    <flux:badge size="sm" color="zinc">Inactif</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="flex items-center justify-end gap-2">
                                    <x-back-office.edit-button
                                        wire:click="openEditEmploye({{ $employeRow['id'] }})"
                                        label="Modifier l'employé"
                                    />

                                    <flux:tooltip :content="$employeRow['isActive'] ? 'Désactiver' : 'Réactiver'">
                                        <flux:button
                                            variant="filled"
                                            size="sm"
                                            :icon="$employeRow['isActive'] ? 'eye-slash' : 'eye'"
                                            :aria-label="$employeRow['isActive'] ? 'Désactiver l\'employé' : 'Réactiver l\'employé'"
                                            wire:click="toggleEmployeActive({{ $employeRow['id'] }})"
                                        />
                                    </flux:tooltip>

                                    <x-back-office.delete-button
                                        wire:click="askToDeleteEmploye({{ $employeRow['id'] }})"
                                        label="Supprimer l'employé"
                                    />
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif

    {{-- Créer / modifier un employé --}}
    <flux:modal wire:model.self="showEmployeModal" wire:key="employe-modal" class="w-full max-w-xl">
        <form wire:submit="saveEmploye" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingEmployeId ? 'Modifier l\'employé' : 'Ajouter un employé' }}</flux:heading>
            </div>

            @if ($employeErrorMessage)
                <flux:callout variant="danger" icon="exclamation-triangle">
                    <flux:callout.text>{{ $employeErrorMessage }}</flux:callout.text>
                </flux:callout>
            @endif

            <div class="grid gap-6 sm:grid-cols-2">
                <flux:field>
                    <flux:label>Prénom</flux:label>
                    <flux:input wire:model="employeFirstName" />
                    <flux:error name="employeFirstName" />
                </flux:field>

                <flux:field>
                    <flux:label>Nom</flux:label>
                    <flux:input wire:model="employeLastName" />
                    <flux:error name="employeLastName" />
                </flux:field>
            </div>

            <div class="grid gap-6 sm:grid-cols-2">
                <flux:field>
                    <flux:label>Téléphone</flux:label>
                    <flux:input wire:model="employePhoneNumber" inputmode="numeric" />
                    <flux:error name="employePhoneNumber" />
                </flux:field>

                <flux:field>
                    <flux:label>Email</flux:label>
                    <flux:input type="email" wire:model="employeEmail" />
                    <flux:error name="employeEmail" />
                </flux:field>
            </div>

            <flux:field>
                <flux:label>Adresse</flux:label>
                <flux:input wire:model="employeAddress" />
                <flux:error name="employeAddress" />
            </flux:field>

            <div class="grid gap-6 sm:grid-cols-3">
                <flux:field>
                    <flux:label>Poste</flux:label>
                    <flux:input wire:model="employeJobTitle" />
                    <flux:error name="employeJobTitle" />
                </flux:field>

                <flux:field>
                    <flux:label>Sexe</flux:label>
                    <flux:select wire:model="employeGender" placeholder="—">
                        @foreach ($this->genderOptions as $genderValue => $genderLabel)
                            <flux:select.option :value="$genderValue">{{ $genderLabel }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="employeGender" />
                </flux:field>

                <flux:field>
                    <flux:label>Catégorie</flux:label>
                    <flux:select wire:model="employeCategoryId" placeholder="—">
                        @foreach ($this->employeCategoryOptions as $categoryOptionId => $categoryOptionName)
                            <flux:select.option :value="$categoryOptionId">{{ $categoryOptionName }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="employeCategoryId" />
                </flux:field>
            </div>

            <div class="space-y-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                <flux:switch wire:model="employeIsActive" label="Actif" />
                <flux:switch wire:model="employeCanSellTicket" label="Peut vendre des billets" />
                <flux:switch wire:model="employeCanCancelPaidBooking" label="Peut annuler une réservation payée" />
                <flux:switch wire:model="employeCanChooseSeats" label="Peut choisir les sièges" />
            </div>

            <div class="flex items-center justify-end gap-2">
                <flux:button variant="ghost" wire:click="closeEmployeModal">Annuler</flux:button>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveEmploye">
                    Enregistrer
                </flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Supprimer un employé --}}
    <flux:modal wire:model.self="showDeleteEmployeModal" wire:key="delete-employe-modal" class="min-w-[22rem] max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Supprimer cet employé ?</flux:heading>
                <flux:text class="mt-2">
                    Voulez-vous vraiment supprimer « {{ $this->deleteEmployeLabel() }} » ? Cette action est irréversible.
                </flux:text>
            </div>

            <div class="flex items-center justify-end gap-2">
                <flux:button variant="ghost" wire:click="closeDeleteEmployeModal">Retour</flux:button>
                <flux:button
                    variant="danger"
                    wire:click="confirmDeleteEmploye"
                    wire:loading.attr="disabled"
                    wire:target="confirmDeleteEmploye"
                >
                    Supprimer
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
