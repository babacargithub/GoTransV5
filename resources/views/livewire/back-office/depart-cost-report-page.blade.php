@php
    $formatFcfa = fn (int $amount): string => number_format($amount, 0, ',', ' ').' FCFA';
    $savedRentalsByBusId = $this->savedCostReport?->busRentals->keyBy('bus_id') ?? collect();
    $canSeeSalesAndProfit = auth()->user()?->can(\App\Enums\PermissionName::FullAccess->value) ?? false;
    $totalRentalFees = $isEditing ? $this->formTotalRentalFees : ($this->savedCostReport?->totalRentalFees() ?? 0);
@endphp

<div class="mx-auto w-full max-w-3xl">
    <flux:button
        :href="route('back-office.departs.index')"
        wire:navigate
        variant="ghost"
        size="sm"
        icon="arrow-left"
        class="mb-4"
    >
        Retour aux départs
    </flux:button>

    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
            <flux:heading size="xl" level="1">Bilan du départ</flux:heading>
            <flux:text class="mt-1">{{ $depart->identifier(with_trajet_prefix: true) }}</flux:text>
            <flux:text class="text-sm">{{ $depart->date->isoFormat('dddd D MMMM YYYY [à] HH[h]mm') }}</flux:text>
        </div>

        @if (! $isEditing && $this->savedCostReport)
            <div class="flex shrink-0 flex-wrap justify-end gap-2">
                @can(\App\Enums\PermissionName::FullAccess->value)
                    <flux:button wire:click="openCashOutModal" icon="banknotes" variant="primary">Enregistrer en caisse</flux:button>
                @endcan
                <flux:button wire:click="startEditing" icon="pencil-square" variant="filled">Modifier</flux:button>
            </div>
        @endif
    </div>

    <x-flash-toasts :status="$flashStatusMessage" :error="$flashErrorMessage" />

    <flux:separator class="my-6" variant="subtle" />

    @if ($this->buses->isEmpty())
        <flux:callout icon="information-circle">
            <flux:callout.text>Ce départ n'a aucun bus : il n'y a pas de bilan à enregistrer.</flux:callout.text>
        </flux:callout>
    @elseif (! $isEditing && ! $this->savedCostReport)
        <flux:callout icon="information-circle">
            <flux:callout.text>Le bilan de ce départ n'a pas encore été enregistré.</flux:callout.text>
        </flux:callout>
    @else
        {{-- Summary --}}
        <div @class(['grid gap-4', 'sm:grid-cols-3' => $canSeeSalesAndProfit])>
            @if ($canSeeSalesAndProfit)
                <flux:card class="space-y-1">
                    <flux:text class="text-sm">Billets vendus</flux:text>
                    <flux:heading size="lg">{{ $formatFcfa($this->totalTicketSales) }}</flux:heading>
                </flux:card>
            @endif
            <flux:card class="space-y-1">
                <flux:text class="text-sm">Location des bus</flux:text>
                <flux:heading size="lg">{{ $formatFcfa($totalRentalFees) }}</flux:heading>
            </flux:card>
            @if ($canSeeSalesAndProfit)
                <flux:card class="space-y-1">
                    <flux:text class="text-sm">Bénéfice</flux:text>
                    @php($profit = $this->totalTicketSales - $totalRentalFees)
                    <flux:heading size="lg" :class="$profit >= 0 ? 'text-green-600' : 'text-red-600'">
                        {{ $formatFcfa($profit) }}
                    </flux:heading>
                </flux:card>
            @endif
        </div>

        @if ($isEditing)
            <form wire:submit="save" class="mt-6 space-y-6">
                @foreach ($this->buses as $bus)
                    <flux:card class="space-y-4" wire:key="cost-report-bus-{{ $bus->id }}">
                        @if ($this->buses->count() > 1)
                            <div class="flex items-center justify-between gap-4">
                                <flux:heading size="lg">{{ $bus->name }}</flux:heading>
                                @if ($canSeeSalesAndProfit)
                                    <flux:text class="text-sm">Billets vendus : {{ $formatFcfa($this->ticketSalesByBusId[$bus->id] ?? 0) }}</flux:text>
                                @endif
                            </div>
                        @endif

                        <div class="grid gap-4 sm:grid-cols-2">
                            <flux:field>
                                <flux:label>Chauffeur bus</flux:label>
                                <x-back-office.owner-name-combobox
                                    :model="'busRentalRows.'.$bus->id.'.busOwnerName'"
                                    :options="$this->knownOwnerNames"
                                />
                                <flux:error name="busRentalRows.{{ $bus->id }}.busOwnerName" />
                            </flux:field>

                            <flux:field>
                                <flux:label>{{ $this->buses->count() > 1 ? 'Frais de location (FCFA)' : 'Montant de la location (FCFA)' }}</flux:label>
                                <flux:input type="number" min="0" step="1" inputmode="numeric" wire:model.live.debounce.300ms="busRentalRows.{{ $bus->id }}.rentalFee" />
                                <flux:error name="busRentalRows.{{ $bus->id }}.rentalFee" />
                            </flux:field>
                        </div>
                    </flux:card>
                @endforeach

                <flux:field>
                    <flux:label>Notes</flux:label>
                    <flux:textarea wire:model="notes" rows="4" />
                    <flux:error name="notes" />
                </flux:field>

                <div class="flex items-center justify-end gap-2">
                    @if ($this->savedCostReport)
                        <flux:button wire:click="cancelEditing" variant="ghost">Annuler</flux:button>
                    @else
                        <flux:button :href="route('back-office.departs.index')" wire:navigate variant="ghost">Annuler</flux:button>
                    @endif

                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save">
                        Enregistrer
                    </flux:button>
                </div>
            </form>
        @else
            <div class="mt-6 space-y-4">
                @foreach ($this->buses as $bus)
                    @php($busRental = $savedRentalsByBusId->get($bus->id))
                    <flux:card class="space-y-3" wire:key="cost-report-bus-{{ $bus->id }}">
                        <flux:heading size="lg">{{ $bus->name }}</flux:heading>
                        <dl @class(['grid gap-3 text-sm sm:grid-cols-3', 'sm:grid-cols-4' => $canSeeSalesAndProfit])>
                            <div>
                                <dt class="text-zinc-500 dark:text-zinc-400">Propriétaire</dt>
                                <dd class="font-medium text-zinc-900 dark:text-white">{{ $busRental?->bus_owner_name ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-zinc-500 dark:text-zinc-400">Frais de location</dt>
                                <dd class="font-medium text-zinc-900 dark:text-white">{{ $busRental ? $formatFcfa($busRental->rental_fee) : '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-zinc-500 dark:text-zinc-400">Déjà payé</dt>
                                <dd class="font-medium text-zinc-900 dark:text-white">{{ $busRental ? $formatFcfa($busRental->paid_amount) : '—' }}</dd>
                            </div>
                            @if ($canSeeSalesAndProfit)
                                <div>
                                    <dt class="text-zinc-500 dark:text-zinc-400">Billets vendus</dt>
                                    <dd class="font-medium text-zinc-900 dark:text-white">{{ $formatFcfa($this->ticketSalesByBusId[$bus->id] ?? 0) }}</dd>
                                </div>
                            @endif
                        </dl>
                    </flux:card>
                @endforeach

                <flux:card class="space-y-2">
                    <flux:heading size="lg">Notes</flux:heading>
                    <flux:text class="whitespace-pre-line">{{ $this->savedCostReport->notes ?: 'Aucune note.' }}</flux:text>
                </flux:card>
            </div>
        @endif
    @endif

    @if ($this->savedCostReport && auth()->user()?->can(\App\Enums\PermissionName::FullAccess->value))
        <flux:modal wire:model.self="showCashOutModal" class="w-full max-w-2xl">
            <form wire:submit="saveCashOut" class="space-y-6">
                <div>
                    <flux:heading size="lg">Enregistrer en caisse</flux:heading>
                    <flux:text class="mt-1">Sortie d'argent : paiement de la location des bus.</flux:text>
                </div>

                <div class="space-y-3">
                    <flux:heading>Montant payé à chaque bus</flux:heading>
                    @foreach ($this->savedCostReport->busRentals as $busRental)
                        <div class="grid items-end gap-3 sm:grid-cols-2" wire:key="cash-out-bus-{{ $busRental->bus_id }}">
                            <div class="text-sm">
                                <div class="font-medium">{{ $this->buses->firstWhere('id', $busRental->bus_id)?->name }} — {{ $busRental->bus_owner_name }}</div>
                                <div class="text-zinc-500">Frais {{ $formatFcfa($busRental->rental_fee) }} · reste {{ $formatFcfa($busRental->remainingAmountToPay()) }}</div>
                            </div>
                            <flux:field>
                                <flux:input
                                    type="number" min="0" max="{{ $busRental->remainingAmountToPay() }}" step="1" inputmode="numeric"
                                    placeholder="Montant payé (FCFA)"
                                    :disabled="$busRental->remainingAmountToPay() === 0"
                                    wire:model.live.debounce.300ms="paidAmountByBusId.{{ $busRental->bus_id }}"
                                />
                                <flux:error name="paidAmountByBusId.{{ $busRental->bus_id }}" />
                            </flux:field>
                        </div>
                    @endforeach
                    <flux:error name="paidAmountByBusId" />
                    <flux:text class="text-sm font-medium">Total payé : {{ $formatFcfa($this->cashOutTotal) }}</flux:text>
                </div>

                @if ($this->cashOutTotal > 0)
                    <flux:separator variant="subtle" />

                    <div class="space-y-3">
                        <flux:heading>Caisses à débiter</flux:heading>
                        @foreach ($cashOutCaisseRows as $rowIndex => $caisseRow)
                            <div class="grid items-start gap-3 sm:grid-cols-[1fr_10rem_auto]" wire:key="cash-out-caisse-row-{{ $rowIndex }}">
                                <flux:field>
                                    <flux:select wire:model.live="cashOutCaisseRows.{{ $rowIndex }}.caisseId" placeholder="Choisir une caisse">
                                        @foreach ($this->cashOutCaisseOptions as $caisseOption)
                                            <flux:select.option :value="$caisseOption['id']">{{ $caisseOption['name'] }} ({{ $formatFcfa($caisseOption['balance']) }})</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                    <flux:error name="cashOutCaisseRows.{{ $rowIndex }}.caisseId" />
                                </flux:field>
                                <flux:field>
                                    <flux:input type="number" min="1" step="1" inputmode="numeric" placeholder="Montant"
                                        :disabled="count($cashOutCaisseRows) === 1"
                                        wire:model.live.debounce.300ms="cashOutCaisseRows.{{ $rowIndex }}.amount" />
                                    <flux:error name="cashOutCaisseRows.{{ $rowIndex }}.amount" />
                                </flux:field>
                                @if (count($cashOutCaisseRows) > 1)
                                    <flux:button wire:click="removeCashOutCaisseRow({{ $rowIndex }})" icon="trash" variant="danger" size="sm" aria-label="Retirer cette caisse" />
                                @endif
                            </div>
                        @endforeach
                        <flux:error name="cashOutCaisseRows" />
                        <flux:button wire:click="addCashOutCaisseRow" icon="plus" size="sm" variant="ghost">Ajouter une caisse</flux:button>
                    </div>

                    <div class="space-y-3">
                        <flux:heading>Comptes à débiter</flux:heading>
                        @foreach ($cashOutAccountRows as $rowIndex => $accountRow)
                            <div class="grid items-start gap-3 sm:grid-cols-[1fr_10rem_auto]" wire:key="cash-out-account-row-{{ $rowIndex }}">
                                <flux:field>
                                    <flux:select wire:model.live="cashOutAccountRows.{{ $rowIndex }}.accountId" placeholder="Choisir un compte">
                                        @foreach ($this->cashOutAccountOptions as $accountOption)
                                            <flux:select.option :value="$accountOption['id']">{{ $accountOption['name'] }} ({{ $formatFcfa($accountOption['balance']) }})</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                    <flux:error name="cashOutAccountRows.{{ $rowIndex }}.accountId" />
                                </flux:field>
                                <flux:field>
                                    <flux:input type="number" min="1" step="1" inputmode="numeric" placeholder="Montant"
                                        :disabled="count($cashOutAccountRows) === 1"
                                        wire:model.live.debounce.300ms="cashOutAccountRows.{{ $rowIndex }}.amount" />
                                    <flux:error name="cashOutAccountRows.{{ $rowIndex }}.amount" />
                                </flux:field>
                                @if (count($cashOutAccountRows) > 1)
                                    <flux:button wire:click="removeCashOutAccountRow({{ $rowIndex }})" icon="trash" variant="danger" size="sm" aria-label="Retirer ce compte" />
                                @endif
                            </div>
                        @endforeach
                        <flux:error name="cashOutAccountRows" />
                        <flux:button wire:click="addCashOutAccountRow" icon="plus" size="sm" variant="ghost">Ajouter un compte</flux:button>
                    </div>

                    <flux:field>
                        <flux:label>Libellé</flux:label>
                        <flux:input wire:model="cashOutLabel" />
                        <flux:error name="cashOutLabel" />
                    </flux:field>

                    <div class="flex items-center justify-end gap-2">
                        <flux:button wire:click="closeCashOutModal" variant="ghost">Annuler</flux:button>
                        <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveCashOut">
                            Enregistrer la sortie de {{ $formatFcfa($this->cashOutTotal) }}
                        </flux:button>
                    </div>
                @endif
            </form>
        </flux:modal>
    @endif
</div>
