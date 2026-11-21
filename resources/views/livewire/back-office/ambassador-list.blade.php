<div class="mx-auto w-full max-w-6xl">
    <div class="flex items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Ambassadeurs</flux:heading>
            <flux:text class="mt-1">Les ambassadeurs, leur contrat et leur statut</flux:text>
        </div>

        <div class="flex flex-wrap items-center justify-end gap-2">
            <flux:button icon="adjustments-horizontal" :href="route('back-office.ambassador-reward-rules.index')" wire:navigate>
                Règles de récompense
            </flux:button>

            @if ($this->abilities['create'])
                <flux:button variant="primary" icon="plus" wire:click="openCreateAmbassador">
                    Ajouter un ambassadeur
                </flux:button>
            @endif
        </div>
    </div>

    <x-flash-toasts />

    <flux:separator class="my-6" variant="subtle" />

    <div class="mb-4 grid gap-4 sm:grid-cols-3">
        <div class="sm:col-span-2">
            <flux:input
                wire:model.live.debounce.300ms="searchTerm"
                icon="magnifying-glass"
                placeholder="Rechercher par nom, téléphone ou code"
                clearable
            />
        </div>

        <flux:select wire:model.live="statusFilter" placeholder="Tous les statuts">
            <flux:select.option value="">Tous les statuts</flux:select.option>
            @foreach ($this->statusOptions as $statusValue => $statusLabel)
                <flux:select.option :value="$statusValue">{{ $statusLabel }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    @if ($this->ambassadors->isEmpty())
        <flux:callout icon="information-circle">
            <flux:callout.heading>Aucun ambassadeur</flux:callout.heading>
            <flux:callout.text>Aucun ambassadeur ne correspond à votre recherche.</flux:callout.text>
        </flux:callout>
    @else
        <div class="overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Ambassadeur</flux:table.column>
                    <flux:table.column>Téléphone</flux:table.column>
                    <flux:table.column>Code</flux:table.column>
                    <flux:table.column>Parrain</flux:table.column>
                    <flux:table.column>Contrat</flux:table.column>
                    <flux:table.column align="center">Clients</flux:table.column>
                    <flux:table.column align="center">Statut</flux:table.column>
                    <flux:table.column />
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->ambassadors as $ambassador)
                        <flux:table.row wire:key="ambassador-{{ $ambassador->id }}">
                            <flux:table.cell variant="strong">{{ $ambassador->full_name }}</flux:table.cell>
                            <flux:table.cell>
                                {{ $ambassador->phone_number }}
                                @if ($ambassador->wave_phone_number)
                                    <div class="text-xs text-zinc-500">Wave : {{ $ambassador->wave_phone_number }}</div>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell><span class="font-mono">{{ $ambassador->code }}</span></flux:table.cell>
                            <flux:table.cell>{{ $ambassador->sponsor?->full_name ?? '—' }}</flux:table.cell>
                            <flux:table.cell>
                                @if ($ambassador->contract_ends_at)
                                    {{ $ambassador->contract_starts_at?->format('d/m/Y') ?? '…' }} → {{ $ambassador->contract_ends_at->format('d/m/Y') }}
                                    @if ($ambassador->contract_ends_at->isPast())
                                        <flux:badge size="sm" color="red">Expiré</flux:badge>
                                    @endif
                                @else
                                    —
                                @endif
                            </flux:table.cell>
                            <flux:table.cell align="center">{{ $ambassador->customers_count }}</flux:table.cell>
                            <flux:table.cell align="center">
                                <flux:badge size="sm" :color="$ambassador->status->badgeColor()">{{ $ambassador->status->label() }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="flex items-center justify-end gap-2">
                                    @if ($ambassador->status === \App\Enums\AmbassadorStatus::PendingApproval && $this->abilities['approve'])
                                        <flux:button size="sm" variant="primary" icon="check" wire:click="openApproval({{ $ambassador->id }})">Valider</flux:button>
                                        <flux:button size="sm" variant="danger" icon="x-mark" wire:click="rejectAmbassador({{ $ambassador->id }})" wire:confirm="Refuser cet ambassadeur ?">Refuser</flux:button>
                                    @endif

                                    @if ($this->abilities['suspend'])
                                        @if ($ambassador->status === \App\Enums\AmbassadorStatus::Active)
                                            <flux:tooltip content="Suspendre">
                                                <flux:button variant="filled" size="sm" icon="pause" aria-label="Suspendre l'ambassadeur" wire:click="suspendAmbassador({{ $ambassador->id }})" />
                                            </flux:tooltip>
                                        @elseif ($ambassador->status === \App\Enums\AmbassadorStatus::Suspended)
                                            <flux:tooltip content="Réactiver">
                                                <flux:button variant="filled" size="sm" icon="play" aria-label="Réactiver l'ambassadeur" wire:click="reactivateAmbassador({{ $ambassador->id }})" />
                                            </flux:tooltip>
                                        @endif
                                    @endif

                                    @if ($this->abilities['recordPromotion'] && $ambassador->status === \App\Enums\AmbassadorStatus::Active)
                                        <flux:tooltip content="Enregistrer un partage ou une vidéo">
                                            <flux:button variant="filled" size="sm" icon="video-camera" aria-label="Enregistrer un partage ou une vidéo de l'ambassadeur" wire:click="openPromotionReward({{ $ambassador->id }})" />
                                        </flux:tooltip>
                                    @endif

                                    <flux:tooltip content="Gains">
                                        <flux:button variant="filled" size="sm" icon="currency-dollar" aria-label="Gains de l'ambassadeur" wire:click="openEarnings({{ $ambassador->id }})" />
                                    </flux:tooltip>

                                    <flux:tooltip content="Règles de récompense">
                                        <flux:button variant="filled" size="sm" icon="adjustments-horizontal" aria-label="Règles de récompense de l'ambassadeur" wire:click="openRewardRules({{ $ambassador->id }})" />
                                    </flux:tooltip>

                                    @if ($this->abilities['edit'])
                                        <x-back-office.edit-button wire:click="openEditAmbassador({{ $ambassador->id }})" label="Modifier l'ambassadeur" />
                                    @endif

                                    @if ($this->abilities['delete'])
                                        <x-back-office.delete-button wire:click="askToDeleteAmbassador({{ $ambassador->id }})" label="Supprimer l'ambassadeur" />
                                    @endif
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>

        <div class="mt-4">
            {{ $this->ambassadors->links() }}
        </div>
    @endif

    {{-- Créer / modifier un ambassadeur --}}
    <flux:modal wire:model.self="showAmbassadorModal" wire:key="ambassador-modal" class="w-full max-w-xl">
        <form wire:submit="saveAmbassador" class="space-y-6">
            <flux:heading size="lg">{{ $editingAmbassadorId ? 'Modifier l\'ambassadeur' : 'Ajouter un ambassadeur' }}</flux:heading>

            <div class="grid gap-6 sm:grid-cols-2">
                <flux:field>
                    <flux:label>Prénom</flux:label>
                    <flux:input wire:model="ambassadorFirstName" />
                    <flux:error name="ambassadorFirstName" />
                </flux:field>

                <flux:field>
                    <flux:label>Nom</flux:label>
                    <flux:input wire:model="ambassadorLastName" />
                    <flux:error name="ambassadorLastName" />
                </flux:field>
            </div>

            <div class="grid gap-6 sm:grid-cols-2">
                <flux:field>
                    <flux:label>Téléphone</flux:label>
                    <flux:input wire:model="ambassadorPhoneNumber" inputmode="numeric" placeholder="77XXXXXXX" />
                    <flux:error name="ambassadorPhoneNumber" />
                </flux:field>

                <flux:field>
                    <flux:label>Numéro Wave</flux:label>
                    <flux:input wire:model="ambassadorWavePhoneNumber" inputmode="numeric" placeholder="Identique au téléphone si vide" />
                    <flux:error name="ambassadorWavePhoneNumber" />
                </flux:field>
            </div>

            <flux:field>
                <flux:label>Code</flux:label>
                <flux:input wire:model="ambassadorCode" class="font-mono uppercase" placeholder="Généré automatiquement si vide" />
                <flux:description>Le code que les clients saisissent pour être rattachés à cet ambassadeur.</flux:description>
                <flux:error name="ambassadorCode" />
            </flux:field>

            <div class="grid gap-6 sm:grid-cols-2">
                <flux:field>
                    <flux:label>Début du contrat</flux:label>
                    <flux:input type="date" wire:model="contractStartsAt" />
                    <flux:error name="contractStartsAt" />
                </flux:field>

                <flux:field>
                    <flux:label>Fin du contrat</flux:label>
                    <flux:input type="date" wire:model="contractEndsAt" />
                    <flux:error name="contractEndsAt" />
                </flux:field>
            </div>

            @if (! $editingAmbassadorId && $this->abilities['createAccount'])
                <div class="space-y-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <flux:switch wire:model.live="createUserAccount" label="Créer un compte utilisateur" description="Permet à l'ambassadeur de se connecter à son espace." />

                    @if ($createUserAccount)
                        <div class="grid gap-6 sm:grid-cols-2">
                            <flux:field>
                                <flux:label>Identifiant</flux:label>
                                <flux:input wire:model="accountUsername" autocomplete="off" />
                                <flux:error name="accountUsername" />
                            </flux:field>

                            <flux:field>
                                <flux:label>Mot de passe</flux:label>
                                <flux:input type="password" wire:model="accountPassword" autocomplete="new-password" viewable />
                                <flux:error name="accountPassword" />
                            </flux:field>
                        </div>
                    @endif
                </div>
            @endif

            <div class="flex items-center justify-end gap-2">
                <flux:button variant="ghost" wire:click="closeAmbassadorModal">Annuler</flux:button>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveAmbassador">
                    Enregistrer
                </flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Valider un ambassadeur parrainé --}}
    <flux:modal wire:model.self="showApprovalModal" wire:key="ambassador-approval-modal" class="w-full max-w-md">
        <form wire:submit="approveAmbassador" class="space-y-6">
            <div>
                <flux:heading size="lg">Valider l'ambassadeur</flux:heading>
                <flux:text class="mt-2">Définissez la période du contrat : il pourra amener des clients dès sa validation.</flux:text>
            </div>

            <div class="grid gap-6 sm:grid-cols-2">
                <flux:field>
                    <flux:label>Début du contrat</flux:label>
                    <flux:input type="date" wire:model="contractStartsAt" />
                    <flux:error name="contractStartsAt" />
                </flux:field>

                <flux:field>
                    <flux:label>Fin du contrat</flux:label>
                    <flux:input type="date" wire:model="contractEndsAt" />
                    <flux:error name="contractEndsAt" />
                </flux:field>
            </div>

            <div class="flex items-center justify-end gap-2">
                <flux:button variant="ghost" wire:click="closeApprovalModal">Annuler</flux:button>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="approveAmbassador">
                    Valider
                </flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Gains d'un ambassadeur, avec approbation manuelle avant échéance --}}
    <flux:modal wire:model.self="showEarningsModal" wire:key="ambassador-earnings-modal" class="w-full max-w-4xl">
        @if ($showEarningsModal && $earningsAmbassadorId)
            <livewire:back-office.ambassador-earnings-dialog :ambassador-id="$earningsAmbassadorId" :key="'earnings-'.$earningsAmbassadorId" />
        @endif
    </flux:modal>

    {{-- Règles de récompense d'un ambassadeur : le même composant que la page des règles, restreint à lui --}}
    <flux:modal wire:model.self="showRewardRulesModal" wire:key="ambassador-reward-rules-modal" class="w-full max-w-4xl">
        @if ($showRewardRulesModal && $rewardRulesAmbassadorId)
            <livewire:back-office.ambassador-reward-rule-list :ambassador-id="$rewardRulesAmbassadorId" :key="'reward-rules-'.$rewardRulesAmbassadorId" />
        @endif
    </flux:modal>

    {{-- Enregistrer un partage de publicité ou une vidéo de promotion --}}
    <flux:modal wire:model.self="showPromotionModal" wire:key="ambassador-promotion-modal" class="w-full max-w-lg">
        @if ($showPromotionModal)
            <form wire:submit="recordPromotionReward" class="space-y-6">
                <div>
                    <flux:heading size="lg">Enregistrer une promotion</flux:heading>
                    <flux:text class="mt-2">Pour {{ $this->promotionAmbassadorLabel() }}. La récompense suit la règle en vigueur et reste en attente d'approbation.</flux:text>
                </div>

                <flux:field>
                    <flux:label>Type de promotion</flux:label>
                    <flux:select wire:model.live="promotionType" placeholder="Choisir un type">
                        @foreach ($this->promotionTypeOptions as $promotionTypeValue => $promotionTypeLabel)
                            <flux:select.option :value="$promotionTypeValue">{{ $promotionTypeLabel }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="promotionType" />
                </flux:field>

                @if ($promotionType !== '')
                    @if ($this->promotionAmountToPay() === null)
                        <flux:callout icon="exclamation-triangle" variant="warning">
                            <flux:callout.text>Aucune règle de récompense ne s'applique à cet ambassadeur pour ce type : rien ne sera enregistré.</flux:callout.text>
                        </flux:callout>
                    @else
                        <flux:callout icon="banknotes">
                            <flux:callout.text>Montant : {{ number_format($this->promotionAmountToPay(), 0, ',', ' ') }} FCFA</flux:callout.text>
                        </flux:callout>
                    @endif
                @endif

                <flux:field>
                    <flux:label>Lien de la publication (optionnel)</flux:label>
                    <flux:input type="url" wire:model="promotionProofUrl" placeholder="https://" />
                    <flux:error name="promotionProofUrl" />
                </flux:field>

                <flux:field>
                    <flux:label>Note (optionnel)</flux:label>
                    <flux:input wire:model="promotionNote" placeholder="Ex. groupe Facebook UCAD" />
                    <flux:error name="promotionNote" />
                </flux:field>

                <div class="flex items-center justify-end gap-2">
                    <flux:button variant="ghost" wire:click="closePromotionModal">Annuler</flux:button>
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="recordPromotionReward">Enregistrer</flux:button>
                </div>
            </form>
        @endif
    </flux:modal>

    {{-- Supprimer un ambassadeur --}}
    <flux:modal wire:model.self="showDeleteModal" wire:key="delete-ambassador-modal" class="min-w-[22rem] max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Supprimer cet ambassadeur ?</flux:heading>
                <flux:text class="mt-2">
                    Voulez-vous vraiment supprimer « {{ $this->deletingAmbassadorLabel() }} » ? Impossible s'il a déjà des clients, des gains ou des filleuls : suspendez-le dans ce cas.
                </flux:text>
            </div>

            <div class="flex items-center justify-end gap-2">
                <flux:button variant="ghost" wire:click="closeDeleteModal">Retour</flux:button>
                <flux:button variant="danger" wire:click="confirmDeleteAmbassador" wire:loading.attr="disabled" wire:target="confirmDeleteAmbassador">
                    Supprimer
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
