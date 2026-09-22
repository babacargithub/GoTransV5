<div class="mx-auto w-full max-w-2xl">
    <flux:button
        :href="route('back-office.departs.index')"
        variant="ghost"
        size="sm"
        icon="arrow-left"
        class="mb-4"
    >
        Retour aux départs
    </flux:button>

    <flux:heading size="xl" level="1">Envoi des rendez-vous</flux:heading>
    <flux:text class="mt-1">{{ $this->departLabel() }}</flux:text>

    <flux:separator class="my-6" variant="subtle" />

    <div class="space-y-6">
        <flux:field>
            <flux:label>Message</flux:label>
            <flux:textarea wire:model="message" rows="6" />
            <flux:description>
                Ce message est envoyé à chaque client. Les jetons ci-dessous sont remplacés par les
                informations de la réservation au moment de l'envoi.
            </flux:description>
        </flux:field>

        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="sm">Jetons disponibles</flux:heading>
            <dl class="mt-3 grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-2">
                @foreach ($availablePlaceholders as $availablePlaceholder)
                    <div class="flex items-baseline gap-2" wire:key="placeholder-{{ $loop->index }}">
                        <dt><flux:badge size="sm" color="zinc">{{ $availablePlaceholder['token'] }}</flux:badge></dt>
                        <dd class="text-sm text-zinc-600 dark:text-zinc-400">{{ $availablePlaceholder['description'] }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>

        <flux:callout icon="information-circle">
            <flux:callout.heading>Sélection des destinataires et envoi à venir</flux:callout.heading>
            <flux:callout.text>
                Le choix des destinataires (clients payés, bus, point de départ, destination) et
                l'envoi effectif des SMS seront ajoutés dans une prochaine itération.
            </flux:callout.text>
        </flux:callout>

        <div class="flex items-center justify-end gap-2">
            <flux:button :href="route('back-office.departs.index')" variant="ghost">Annuler</flux:button>
            <flux:button variant="primary" disabled>Envoyer les rendez-vous</flux:button>
        </div>
    </div>
</div>
