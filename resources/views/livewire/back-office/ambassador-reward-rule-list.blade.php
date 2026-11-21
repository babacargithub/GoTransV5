<div @class(['mx-auto w-full max-w-6xl' => ! $this->restrictedAmbassador])>
    <div class="flex items-start justify-between gap-4">
        <div>
            @if ($this->restrictedAmbassador)
                <flux:heading size="lg" level="2">Règles de récompense de {{ $this->restrictedAmbassador->full_name }}</flux:heading>
                <flux:text class="mt-1">Ces règles ne concernent que cet ambassadeur</flux:text>
            @else
                <flux:heading size="xl" level="1">Règles de récompense</flux:heading>
                <flux:text class="mt-1">Ce que gagnent les ambassadeurs, pour qui et pendant quelle période</flux:text>
            @endif
        </div>

        @if ($this->canManageRules())
            <flux:button variant="primary" icon="plus" wire:click="openCreateRule">Ajouter une règle</flux:button>
        @endif
    </div>

    <x-flash-toasts />

    <flux:callout class="mt-6" icon="information-circle">
        <flux:callout.text>
            Sans règle active, aucune récompense n'est versée. Une règle dédiée à un ambassadeur prime sur la règle générale du même type,
            même en pause : elle suspend alors la récompense pour cet ambassadeur. Supprimez-la pour qu'il retombe sur la règle générale.
            Modifier une règle ne change que les prochaines récompenses.
        </flux:callout.text>
    </flux:callout>

    <flux:separator class="my-6" variant="subtle" />

    @if ($this->rules->isEmpty())
        <flux:callout icon="exclamation-triangle" variant="warning">
            <flux:callout.heading>Aucune règle</flux:callout.heading>
            <flux:callout.text>
                @if ($this->restrictedAmbassador)
                    Aucune règle dédiée à cet ambassadeur : les règles générales s'appliquent.
                @else
                    Aucun ambassadeur ne gagne de récompense pour le moment.
                @endif
            </flux:callout.text>
        </flux:callout>
    @else
        <div class="overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Récompense</flux:table.column>
                    @unless ($this->restrictedAmbassador)
                        <flux:table.column>Ambassadeur</flux:table.column>
                    @endunless
                    <flux:table.column align="end">Montant</flux:table.column>
                    <flux:table.column>Période</flux:table.column>
                    <flux:table.column align="center">Statut</flux:table.column>
                    <flux:table.column />
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->rules as $rule)
                        @php
                            $isInPeriod = ($rule->starts_at === null || $rule->starts_at->isPast()) && ($rule->ends_at === null || $rule->ends_at->isFuture());
                        @endphp
                        <flux:table.row wire:key="reward-rule-{{ $rule->id }}">
                            <flux:table.cell variant="strong">{{ $rule->earning_type->label() }}</flux:table.cell>
                            @unless ($this->restrictedAmbassador)
                                <flux:table.cell>{{ $rule->ambassador?->full_name ?? 'Tous les ambassadeurs' }}</flux:table.cell>
                            @endunless
                            <flux:table.cell align="end">{{ number_format($rule->amount, 0, ',', ' ') }} FCFA</flux:table.cell>
                            <flux:table.cell>
                                @if ($rule->starts_at || $rule->ends_at)
                                    {{ $rule->starts_at?->format('d/m/Y H:i') ?? '…' }} → {{ $rule->ends_at?->format('d/m/Y H:i') ?? '…' }}
                                @else
                                    Sans limite
                                @endif
                            </flux:table.cell>
                            <flux:table.cell align="center">
                                @if (! $rule->is_enabled)
                                    <flux:badge size="sm" color="zinc">En pause</flux:badge>
                                @elseif (! $isInPeriod)
                                    <flux:badge size="sm" color="amber">Hors période</flux:badge>
                                @else
                                    <flux:badge size="sm" color="green">Active</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                @if ($this->canManageRules())
                                    <div class="flex items-center justify-end gap-2">
                                        <flux:tooltip :content="$rule->is_enabled ? 'Mettre en pause' : 'Activer'">
                                            <flux:button
                                                variant="filled"
                                                size="sm"
                                                :icon="$rule->is_enabled ? 'pause' : 'play'"
                                                aria-label="{{ $rule->is_enabled ? 'Mettre la règle en pause' : 'Activer la règle' }}"
                                                wire:click="toggleRule({{ $rule->id }})"
                                            />
                                        </flux:tooltip>
                                        <x-back-office.edit-button wire:click="openEditRule({{ $rule->id }})" label="Modifier la règle" />
                                        <x-back-office.delete-button wire:click="askToDeleteRule({{ $rule->id }})" label="Supprimer la règle" />
                                    </div>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif

    {{-- Créer / modifier une règle --}}
    <flux:modal wire:model.self="showRuleModal" wire:key="reward-rule-modal" class="w-full max-w-xl">
        <form wire:submit="saveRule" class="space-y-6">
            <flux:heading size="lg">{{ $editingRuleId ? 'Modifier la règle' : 'Ajouter une règle' }}</flux:heading>

            <flux:field>
                <flux:label>Récompense</flux:label>
                <flux:select wire:model="ruleEarningType" placeholder="Choisir une récompense">
                    @foreach ($this->earningTypeOptions as $earningTypeValue => $earningTypeLabel)
                        <flux:select.option :value="$earningTypeValue">{{ $earningTypeLabel }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="ruleEarningType" />
            </flux:field>

            <flux:field>
                <flux:label>Montant (FCFA)</flux:label>
                <flux:input
                    type="text"
                    inputmode="numeric"
                    autocomplete="off"
                    placeholder="0"
                    value="{{ $ruleAmount !== null ? number_format($ruleAmount, 0, ',', ' ') : '' }}"
                    wire:key="reward-rule-amount-{{ $editingRuleId ?? 'new' }}-{{ $showRuleModal ? 1 : 0 }}"
                    x-on:input="
                        const digits = $event.target.value.replace(/\D/g, '');
                        $event.target.value = digits.replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
                        $wire.set('ruleAmount', digits === '' ? null : Number(digits));
                    "
                />
                <flux:error name="ruleAmount" />
            </flux:field>

            @unless ($this->restrictedAmbassador)
                <flux:field>
                    <flux:label>Ambassadeur</flux:label>
                    <flux:select wire:model="ruleAmbassadorId">
                        <flux:select.option value="">Tous les ambassadeurs</flux:select.option>
                        @foreach ($this->ambassadorOptions as $ambassadorId => $ambassadorName)
                            <flux:select.option :value="$ambassadorId">{{ $ambassadorName }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="ruleAmbassadorId" />
                </flux:field>
            @endunless

            <div class="grid gap-6 sm:grid-cols-2">
                <flux:field>
                    <flux:label>Début (optionnel)</flux:label>
                    <flux:input type="datetime-local" wire:model="ruleStartsAt" />
                    <flux:error name="ruleStartsAt" />
                </flux:field>

                <flux:field>
                    <flux:label>Fin (optionnel)</flux:label>
                    <flux:input type="datetime-local" wire:model="ruleEndsAt" />
                    <flux:error name="ruleEndsAt" />
                </flux:field>
            </div>

            <flux:switch wire:model="ruleIsEnabled" label="Règle active" description="En pause, la règle ne récompense personne, et une règle dédiée en pause suspend aussi la règle générale pour son ambassadeur." />

            <div class="flex items-center justify-end gap-2">
                <flux:button variant="ghost" wire:click="closeRuleModal">Annuler</flux:button>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveRule">Enregistrer</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Supprimer une règle --}}
    <flux:modal wire:model.self="showDeleteModal" wire:key="reward-rule-delete-modal" class="w-full max-w-md">
        <div class="space-y-6">
            <flux:heading size="lg">Supprimer la règle ?</flux:heading>
            <flux:text>Les récompenses déjà créées ne sont pas modifiées. Pour suspendre temporairement une règle, mettez-la plutôt en pause.</flux:text>
            <div class="flex items-center justify-end gap-2">
                <flux:button variant="ghost" wire:click="closeDeleteModal">Annuler</flux:button>
                <flux:button variant="danger" wire:click="confirmDeleteRule">Supprimer</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
