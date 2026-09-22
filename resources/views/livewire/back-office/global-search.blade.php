<div
    class="flex items-center"
    x-data="{
        toastVisible: false,
        toastMessage: '',
        toastTimeout: null,
        showToast(message) {
            this.toastMessage = message;
            this.toastVisible = true;
            clearTimeout(this.toastTimeout);
            this.toastTimeout = setTimeout(() => this.toastVisible = false, 5000);
        },
    }"
    x-on:global-search-no-result.window="showToast($event.detail.message)"
    x-on:keydown.window.cmd.k.prevent="$wire.set('showSearchInput', true)"
    x-on:keydown.window.ctrl.k.prevent="$wire.set('showSearchInput', true)"
>
    <div class="flex items-center gap-1.5">
        @if ($showSearchInput)
            <div class="w-56" wire:key="global-search-input">
                <flux:input
                    wire:model.live.debounce.400ms="searchQuery"
                    kbd="⌘K"
                    size="sm"
                    icon="magnifying-glass"
                    placeholder="Numéro de téléphone…"
                    autofocus
                    x-on:keydown.escape="$wire.toggleSearchInput()"
                />
            </div>

            <flux:button
                size="sm"
                variant="subtle"
                icon="x-mark"
                square
                aria-label="Fermer la recherche"
                wire:click="toggleSearchInput"
            />
        @else
            <flux:tooltip content="Rechercher un client">
                <flux:button
                    size="sm"
                    variant="subtle"
                    icon="magnifying-glass"
                    square
                    aria-label="Rechercher"
                    wire:click="toggleSearchInput"
                />
            </flux:tooltip>
        @endif
    </div>

    @if ($searchValidationMessage)
        <flux:text class="ms-2 text-xs text-red-600 dark:text-red-400">{{ $searchValidationMessage }}</flux:text>
    @endif

    {{-- "Aucun résultat" red toast --}}
    <div
        x-show="toastVisible"
        x-transition
        x-cloak
        class="fixed bottom-6 right-6 z-50 flex items-center gap-2 rounded-lg bg-red-600 px-4 py-3 text-sm font-medium text-white shadow-lg"
        role="alert"
    >
        <flux:icon.exclamation-triangle variant="mini" class="size-4" />
        <span x-text="toastMessage"></span>
    </div>

    {{-- Résultat de la recherche --}}
    @php($customerHeader = $this->customerHeader)

    <flux:modal wire:model.self="showResultDialog" wire:key="global-search-result" class="w-full max-w-5xl">
        @if ($customerHeader === null)
            <flux:callout icon="information-circle">
                <flux:callout.text>Le client n'est plus disponible.</flux:callout.text>
            </flux:callout>
        @else
            <div class="space-y-6">
                {{-- Header --}}
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="space-y-1">
                        <flux:heading size="lg">{{ $customerHeader['fullName'] }}</flux:heading>
                        <flux:text class="text-sm">
                            <a href="tel:{{ $customerHeader['phoneNumber'] }}" class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">
                                {{ $customerHeader['phoneNumber'] }}
                            </a>
                        </flux:text>
                        @if ($customerHeader['createdAtLabel'])
                            <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">
                                Client depuis {{ $customerHeader['createdAtLabel'] }}
                            </flux:text>
                        @endif
                    </div>

                    <flux:badge color="blue" size="lg" icon="ticket">
                        {{ $customerHeader['totalBookings'] }} réservation(s)
                    </flux:badge>
                </div>

                @if ($flashStatusMessage)
                    <flux:callout variant="success" icon="check-circle" wire:key="search-flash-status">
                        <flux:callout.text>{{ $flashStatusMessage }}</flux:callout.text>
                    </flux:callout>
                @endif

                @if ($flashErrorMessage)
                    <flux:callout variant="danger" icon="exclamation-triangle" wire:key="search-flash-error">
                        <flux:callout.text>{{ $flashErrorMessage }}</flux:callout.text>
                    </flux:callout>
                @endif

                {{-- Tabs --}}
                @php($resultTabs = ['reservations' => 'Réservations', 'past' => 'Voyages passés', 'payments' => 'Paiements'])

                <div class="flex gap-1 border-b border-zinc-200 dark:border-zinc-700">
                    @foreach ($resultTabs as $tabKey => $tabLabel)
                        <button
                            type="button"
                            wire:click="$set('activeResultTab', '{{ $tabKey }}')"
                            @class([
                                '-mb-px border-b-2 px-3 py-2 text-sm font-medium transition',
                                'border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-400' => $activeResultTab === $tabKey,
                                'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200' => $activeResultTab !== $tabKey,
                            ])
                        >
                            {{ $tabLabel }}
                        </button>
                    @endforeach
                </div>

                <div>
                    @if ($activeResultTab === 'reservations')
                        @include('livewire.back-office.partials.customer-bookings-table', [
                            'rows' => $this->currentBookingRows,
                            'canModify' => true,
                            'canCancel' => true,
                            'canTransfer' => true,
                            'emptyMessage' => 'Aucune réservation en cours pour ce client.',
                        ])
                    @elseif ($activeResultTab === 'past')
                        @include('livewire.back-office.partials.customer-bookings-table', [
                            'rows' => $this->pastBookingRows,
                            'canModify' => false,
                            'canCancel' => false,
                            'canTransfer' => true,
                            'emptyMessage' => 'Aucun voyage passé pour ce client.',
                        ])
                    @else
                        <flux:callout icon="wrench-screwdriver">
                            <flux:callout.text>L'historique des paiements sera ajouté ici prochainement.</flux:callout.text>
                        </flux:callout>
                    @endif
                </div>

                <div class="flex justify-end">
                    <flux:button variant="ghost" wire:click="closeResultDialog">Fermer</flux:button>
                </div>
            </div>
        @endif
    </flux:modal>

    @include('livewire.back-office.partials.booking-action-modals')
</div>
