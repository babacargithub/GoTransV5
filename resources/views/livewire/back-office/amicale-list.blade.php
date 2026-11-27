<div class="mx-auto w-full max-w-6xl">
    <div class="flex items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Amicales</flux:heading>
            <flux:text class="mt-1">Les amicales partenaires, leur site et leur commission par billet</flux:text>
        </div>

        <flux:button variant="primary" icon="plus" wire:click="openCreateAmicale">
            Ajouter une amicale
        </flux:button>
    </div>

    <x-flash-toasts />

    <flux:separator class="my-6" variant="subtle" />

    @if ($this->amicales->isEmpty())
        <flux:callout icon="information-circle">
            <flux:callout.heading>Aucune amicale</flux:callout.heading>
            <flux:callout.text>Ajoutez une première amicale pour lui ouvrir son site et son espace.</flux:callout.text>
        </flux:callout>
    @else
        <div class="overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Amicale</flux:table.column>
                    <flux:table.column>Site</flux:table.column>
                    <flux:table.column>Trajets</flux:table.column>
                    <flux:table.column>Commission / billet</flux:table.column>
                    <flux:table.column>Billets payés</flux:table.column>
                    <flux:table.column>Statut</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->amicales as $amicale)
                        <flux:table.row wire:key="amicale-{{ $amicale->id }}">
                            <flux:table.cell variant="strong">{{ $amicale->name }}</flux:table.cell>
                            <flux:table.cell class="font-mono text-xs">{{ $amicale->slug }}.{{ config('app.public_website_domain') }}</flux:table.cell>
                            <flux:table.cell class="text-xs">{{ $amicale->trajets->isEmpty() ? 'Tous les trajets' : $amicale->trajets->map(fn ($trajet) => $trajet->public_name ?? $trajet->name)->join(', ') }}</flux:table.cell>
                            <flux:table.cell>{{ number_format($amicale->commission_per_ticket, 0, ',', ' ') }} FCFA</flux:table.cell>
                            <flux:table.cell>{{ $amicale->paid_bookings_count }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge :color="$amicale->is_active ? 'green' : 'zinc'">{{ $amicale->is_active ? 'Active' : 'Désactivée' }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:button size="sm" variant="ghost" wire:click="openEditTrajets({{ $amicale->id }})">Trajets</flux:button>
                                <flux:button size="sm" variant="ghost" wire:click="toggleAmicaleActive({{ $amicale->id }})" wire:confirm="{{ $amicale->is_active ? 'Désactiver cette amicale ? Son site et son espace ne seront plus accessibles.' : 'Réactiver cette amicale ?' }}">
                                    {{ $amicale->is_active ? 'Désactiver' : 'Activer' }}
                                </flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif

    <flux:modal wire:model.self="showAmicaleModal" wire:key="amicale-modal" class="w-full max-w-xl">
        <form wire:submit="saveAmicale" class="space-y-6">
            <flux:heading size="lg">Ajouter une amicale</flux:heading>

            <flux:field>
                <flux:label>Nom</flux:label>
                <flux:input wire:model="amicaleName" placeholder="Amicale des étudiants de ..." />
                <flux:error name="amicaleName" />
            </flux:field>

            <flux:field>
                <flux:label>Sous-domaine</flux:label>
                <flux:input wire:model="amicaleSlug" class="font-mono" placeholder="amicalex" />
                <flux:description>Son site sera « sous-domaine.{{ config('app.public_website_domain') }} ».</flux:description>
                <flux:error name="amicaleSlug" />
            </flux:field>

            <flux:field>
                <flux:label>Message de bienvenue</flux:label>
                <flux:input wire:model="welcomeMessage" placeholder="Par défaut : « Nom de l'amicale en partenariat avec Globe One Transports »" />
                <flux:error name="welcomeMessage" />
            </flux:field>

            <flux:field>
                <flux:label>Commission par billet (FCFA)</flux:label>
                <flux:input wire:model="commissionPerTicket" type="number" min="0" inputmode="numeric" />
                <flux:error name="commissionPerTicket" />
            </flux:field>

            <flux:checkbox.group wire:model="servedTrajetIds" label="Trajets proposés" description="Aucun coché : tous les trajets sont proposés.">
                @foreach ($this->trajetOptions as $trajetOption)
                    <flux:checkbox :value="(string) $trajetOption->id" :label="$trajetOption->public_name ?? $trajetOption->name" wire:key="trajet-option-{{ $trajetOption->id }}" />
                @endforeach
            </flux:checkbox.group>

            <div class="space-y-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                <flux:text class="font-medium">Compte de l'amicale</flux:text>
                <div class="grid gap-6 sm:grid-cols-2">
                    <flux:field>
                        <flux:label>Identifiant</flux:label>
                        <flux:input wire:model="accountUsername" autocomplete="off" />
                        <flux:error name="accountUsername" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Mot de passe</flux:label>
                        <flux:input wire:model="accountPassword" type="password" autocomplete="new-password" />
                        <flux:error name="accountPassword" />
                    </flux:field>
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <flux:button type="button" wire:click="closeAmicaleModal">Annuler</flux:button>
                <flux:button type="submit" variant="primary">Créer l'amicale</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model.self="showTrajetsModal" wire:key="amicale-trajets-modal" class="w-full max-w-md">
        <form wire:submit="saveServedTrajets" class="space-y-6">
            <flux:heading size="lg">Trajets de l'amicale</flux:heading>


            <flux:checkbox.group wire:model="servedTrajetIds" label="Trajets proposés" description="Aucun coché : tous les trajets sont proposés.">
                @foreach ($this->trajetOptions as $trajetOption)
                    <flux:checkbox :value="(string) $trajetOption->id" :label="$trajetOption->public_name ?? $trajetOption->name" wire:key="edit-trajet-option-{{ $trajetOption->id }}" />
                @endforeach
            </flux:checkbox.group>

            <div class="flex justify-end gap-2">
                <flux:button type="button" wire:click="closeEditTrajets">Annuler</flux:button>
                <flux:button type="submit" variant="primary">Enregistrer</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
